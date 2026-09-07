<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Authenticate the fixed system user.
     */
    public function login(Request $request): JsonResponse
    {
        // Validate the credentials sent by the frontend/Postman.
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Find the fixed user account by email.
        $user = User::where('email', $credentials['email'])->first();

        // Reject invalid credentials.
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        // Create a Sanctum API token for this login session.
        $token = $user->createToken('crystal-report-api')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],

            'token' => $token,
        ]);
    }

    /**
     * Log out the current session by deleting its token.
     */
    public function logout(Request $request): JsonResponse
    {
        // Delete the token that was used for this request.
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }
}
