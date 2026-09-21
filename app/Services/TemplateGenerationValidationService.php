<?php

namespace App\Services;

use App\Models\StudentRecord;
use App\Models\Template;
use Illuminate\Support\Facades\Storage;

class TemplateGenerationValidationService
{
    /**
     * Return blocking template/data errors before preview or final generation.
     *
     * @param iterable<int, StudentRecord> $students
     * @return array<int, array<string, mixed>>
     */
    public function validate(Template $template, iterable $students): array
    {
        $errors = [];
        $template->loadMissing(['elements.dataMapping']);

        foreach (['front', 'back'] as $side) {
            $enabled = $side === 'front'
                ? (bool) $template->has_front
                : (bool) $template->has_back;
            $path = $side === 'front'
                ? $template->front_design_path
                : $template->back_design_path;

            if ($enabled && (!$path || !Storage::disk('local')->exists($path))) {
                $errors[] = [
                    'scope' => 'template',
                    'side' => $side,
                    'reason' => "The enabled {$side} design is missing.",
                ];
            }
        }

        $enabledSides = collect([
            (bool) $template->has_front ? 'front' : null,
            (bool) $template->has_back ? 'back' : null,
        ])->filter();

        $visibleElements = $template->elements
            ->whereIn('side', $enabledSides)
            ->where('visible', true)
            ->values();

        foreach ($visibleElements as $element) {
            if ($element->type === 'barcode' && !$this->fieldFor($element)) {
                $errors[] = [
                    'scope' => 'template_element',
                    'element_id' => $element->id,
                    'reason' => 'The barcode element needs a field or data mapping.',
                ];
            }
        }

        foreach ($students as $student) {
            foreach ($visibleElements as $element) {
                $field = $this->fieldFor($element);
                $value = $field ? $this->valueFor($student, $field) : null;

                if (in_array($element->type, ['photo', 'profile_photo'], true)) {
                    if (!$student->photo || !$student->photo->file_path
                        || !Storage::disk('local')->exists($student->photo->file_path)) {
                        $errors[] = $this->studentError(
                            $student,
                            $element,
                            'A matched profile photo is required by the template.'
                        );
                    }

                    continue;
                }

                if ($element->type === 'static_text') {
                    continue;
                }

                $required = $element->dataMapping?->is_required
                    || in_array($element->type, [
                        'barcode',
                        'student_name',
                        'student_id',
                        'student_id_grid',
                    ], true);

                if ($required && !$field) {
                    $errors[] = $this->studentError(
                        $student,
                        $element,
                        'The template element has no configured data source.'
                    );
                } elseif ($required && ($value === null || $value === '')) {
                    $errors[] = $this->studentError(
                        $student,
                        $element,
                        "No value is available for field '{$field}'."
                    );
                }
            }
        }

        return $errors;
    }

    private function fieldFor($element): ?string
    {
        $field = trim((string) ($element->field_name ?? ''));

        if ($field !== '') {
            return $field;
        }

        $mappedField = trim((string) ($element->dataMapping?->source_field ?? ''));

        if ($mappedField !== '') {
            return $mappedField;
        }

        // Legacy editor records identify built-in fields by element type.
        // The element itself remains the explicit template opt-in.
        return $element->exists && $element->side
            ? match ($element->type) {
            'barcode' => 'barcode',
            'student_id', 'student_id_grid' => 'student_id',
            'student_name' => 'student_name',
            default => null,
            }
            : null;
    }

    private function valueFor(StudentRecord $student, string $field): ?string
    {
        if ($field === 'barcode') {
            return $student->barcode !== null
                ? (string) $student->barcode
                : null;
        }

        if ($field === 'student_id') {
            return $student->student_id !== null
                ? (string) $student->student_id
                : null;
        }

        if ($field === 'student_name') {
            return $student->student_name !== null
                ? (string) $student->student_name
                : null;
        }

        if ($field === 'manufacture_date') {
            return now()->toDateString();
        }

        $data = is_array($student->data) ? $student->data : [];

        return array_key_exists($field, $data)
            ? (string) $data[$field]
            : null;
    }

    private function studentError(StudentRecord $student, $element, string $reason): array
    {
        return [
            'scope' => 'student',
            'student_record_id' => $student->id,
            'barcode' => $student->barcode,
            'element_id' => $element->id,
            'element_type' => $element->type,
            'reason' => $reason,
        ];
    }
}
