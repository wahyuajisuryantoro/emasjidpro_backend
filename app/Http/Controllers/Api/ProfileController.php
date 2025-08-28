<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Helpers\GoogleCloudStorageHelper;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    public function getProfile()
    {
        try {
            $user = auth()->user();
            $pictureUrl = null;
            if ($user->picture) {
                $pictureUrl = GoogleCloudStorageHelper::getFileUrl($user->picture);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile data retrieved successfully',
                'data' => [
                    'no' => $user->no,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'phone' => $user->phone,
                    'address' => $user->address,
                    'city' => $user->city,
                    'birth' => $user->birth,
                    'sex' => $user->sex,
                    'picture' => $pictureUrl,
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updatePictureProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'picture' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = auth()->user();
            $file = $request->file('picture');
            $filename = 'picture_profiles/' . $user->no . '/' . Str::uuid() . '.' . $file->getClientOriginalExtension();
            try {
                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if (!$uploaded) {
                    throw new Exception('Storage::put returned false');
                }
            } catch (Exception $e) {

                throw new Exception('Google Cloud Storage upload failed: ' . $e->getMessage());
            }

            $url = GoogleCloudStorageHelper::getFileUrl($filename);

            $user->update([
                'picture' => $filename
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile picture updated successfully',
                'data' => [
                    'picture_url' => $url,
                    'picture_path' => $filename
                ]
            ], 200);

        } catch (Exception $e) {
            \Log::error('Profile picture update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = auth()->user();
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|unique:user,email,' . $user->no . ',no',
                'phone' => 'sometimes|required|string|max:20',
                'address' => 'sometimes|nullable|string|max:500',
                'city' => 'sometimes|nullable|string|max:100',
                'birth' => 'sometimes|nullable|date',
                'sex' => 'sometimes|nullable|in:L,P',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            $updateData = [];
            $fillableFields = [
                'name',
                'email',
                'phone',
                'address',
                'city',
                'birth',
                'sex'
            ];

            foreach ($fillableFields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->input($field);
                }
            }
            $user->update($updateData);
            $pictureUrl = null;
            if ($user->picture) {
                $pictureUrl = GoogleCloudStorageHelper::getFileUrl($user->picture);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'no' => $user->no,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'phone' => $user->phone,
                    'address' => $user->address,
                    'city' => $user->city,
                    'birth' => $user->birth,
                    'sex' => $user->sex,
                    'picture' => $pictureUrl,
                ]
            ], 200);

        } catch (Exception $e) {
            \Log::error('Profile update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage()
            ], 500);
        }
    }


    public function updatePassword(Request $request)
    {
        try {
            $user = auth()->user();
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
                'new_password_confirmation' => 'required|string',
            ], [
                'current_password.required' => 'Password saat ini harus diisi',
                'new_password.required' => 'Password baru harus diisi',
                'new_password.min' => 'Password baru minimal 8 karakter',
                'new_password.confirmed' => 'Konfirmasi password tidak sesuai',
                'new_password_confirmation.required' => 'Konfirmasi password harus diisi',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }
            if (md5($request->current_password) !== $user->password) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password saat ini tidak sesuai'
                ], 422);
            }
            if (md5($request->new_password) === $user->password) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password baru harus berbeda dari password saat ini'
                ], 422);
            }
            $user->update([
                'password' => md5($request->new_password)
            ]);
            $response = response()->json([
                'success' => true,
                'message' => 'Password berhasil diperbarui. Silakan login ulang dengan password baru.'
            ], 200);
            register_shutdown_function(function () use ($user) {
                try {
                    $user->tokens()->delete();
                } catch (Exception $e) {
                    \Log::error('Failed to delete tokens after password update: ' . $e->getMessage());
                }
            });

            return $response;

        } catch (Exception $e) {
            \Log::error('Password update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui password: ' . $e->getMessage()
            ], 500);
        }
    }
}
