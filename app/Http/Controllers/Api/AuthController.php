<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $username = $request->username;
        $password = $request->password;
        $password_hashed = md5($password);

        $user = User::where('username', $username)
            ->where('password', $password_hashed)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username atau password salah'
            ], 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        $user->last_login = now();
        $user->last_ipaddress = $request->ip();
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Login berhasil',
            'token' => $token,
            'user_id' => $user->no,
            'username' => $user->username,
            'name' => $user->name,
            'category' => $user->category,
            'replika' => $user->replika,
            'referral' => $user->referral,
            'subdomain' => $user->subdomain,
            'link' => $user->link,
            'number_id' => $user->number_id,
            'birth' => $user->birth,
            'sex' => $user->sex,
            'address' => $user->address,
            'city' => $user->city,
            'phone' => $user->phone,
            'email' => $user->email,
            'bank_name' => $user->bank_name,
            'bank_branch' => $user->bank_branch,
            'bank_account_number' => $user->bank_account_number,
            'bank_account_name' => $user->bank_account_name,
            'last_login' => $user->last_login,
            'last_ipaddress' => $user->last_ipaddress,
            'picture' => $user->picture,
            'date' => $user->date,
            'publish' => $user->publish
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout berhasil'
        ]);
    }
}
