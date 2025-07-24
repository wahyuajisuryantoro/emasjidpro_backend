<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Helpers\GoogleCloudStorageHelper;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    public function getDataSetting()
    {
        try {
            $username = Auth::user()->username;

            $setting = DB::table('keu_setting')
                ->select('username', 'name', 'pengurus', 'logo', 'publish', 'active')
                ->where('username', $username)
                ->where('publish', '1')
                ->where('active', '1')
                ->first();

            if ($setting) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Data setting berhasil dimuat',
                    'setting' => [
                        'username' => $setting->username,
                        'name' => $setting->name,
                        'pengurus' => $setting->pengurus,
                        'logo' => $setting->logo,
                        'publish' => $setting->publish,
                        'active' => $setting->active
                    ]
                ], 200);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data setting tidak ditemukan atau tidak aktif untuk user ini'
                ], 404);
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memuat data setting: ' . $e->getMessage()
            ], 500);
        }
    }

     public function getDataMasjidAndSetting()
    {
        try {
            $username = Auth::user()->username;

            $masjid = DB::table('masjid')
                ->select('name', 'address', 'city', 'tahun_berdiri')
                ->where('username', $username)
                ->first();

            $setting = DB::table('keu_setting')
                ->select('username', 'name', 'pengurus', 'logo', 'publish', 'active')
                ->where('username', $username)
                ->where('publish', '1')
                ->where('active', '1')
                ->first();

            $masjidData = $masjid ? [
                'name' => $masjid->name,
                'address' => $masjid->address,
                'city' => $masjid->city,
                'tahun_berdiri' => $masjid->tahun_berdiri
            ] : null;
            
            $settingData = null;
            if ($setting) {
                $logoUrl = null;
                if ($setting->logo) {
                    $logoUrl = GoogleCloudStorageHelper::getFileUrl($setting->logo);
                }

                $settingData = [
                    'username' => $setting->username,
                    'name' => $setting->name,
                    'pengurus' => $setting->pengurus,
                    'logo' => $logoUrl,
                    'logo_path' => $setting->logo,
                    'publish' => $setting->publish,
                    'active' => $setting->active
                ];
                
                \Log::info('Logo URL generated: ' . $logoUrl);
                \Log::info('Logo path: ' . $setting->logo);
            }

            return response()->json([
                'success' => true,
                'message' => 'Data retrieved successfully',
                'data' => [
                    'masjid' => $masjidData,
                    'setting' => $settingData
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateDataMasjid(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:200',
                'address' => 'sometimes|required|string|max:500',
                'city' => 'sometimes|required|string|max:100',
                'tahun_berdiri' => 'sometimes|required|integer|min:1800|max:' . date('Y'),
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $username = Auth::user()->username;
            $masjid = DB::table('masjid')->where('username', $username)->first();

            if (!$masjid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data masjid tidak ditemukan'
                ], 404);
            }
            DB::beginTransaction();
            try {
                $updateMasjidData = [];
                $fillableFields = ['name', 'address', 'city', 'tahun_berdiri'];

                foreach ($fillableFields as $field) {
                    if ($request->has($field)) {
                        $updateMasjidData[$field] = $request->input($field);
                    }
                }

                DB::table('masjid')
                    ->where('username', $username)
                    ->update($updateMasjidData);
                if ($request->has('name')) {
                    DB::table('keu_setting')
                        ->where('username', $username)
                        ->where('publish', '1')
                        ->where('active', '1')
                        ->update(['name' => $request->input('name')]);
                }
                DB::commit();
                $updatedMasjid = DB::table('masjid')
                    ->select('name', 'address', 'city', 'tahun_berdiri')
                    ->where('username', $username)
                    ->first();

                return response()->json([
                    'success' => true,
                    'message' => 'Data masjid updated successfully',
                    'data' => [
                        'masjid' => [
                            'name' => $updatedMasjid->name,
                            'address' => $updatedMasjid->address,
                            'city' => $updatedMasjid->city,
                            'tahun_berdiri' => $updatedMasjid->tahun_berdiri
                        ]
                    ]
                ], 200);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update data masjid: ' . $e->getMessage()
            ], 500);
        }
    }
    public function updateSetting(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'pengurus' => 'sometimes|required|string|max:255',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $username = Auth::user()->username;
            $setting = DB::table('keu_setting')
                ->where('username', $username)
                ->where('publish', '1')
                ->where('active', '1')
                ->first();

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data setting tidak ditemukan'
                ], 404);
            }

            $updateData = [];

            if ($request->has('pengurus')) {
                $updateData['pengurus'] = $request->input('pengurus');
            }

            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $filename = 'setting/' . $username . '/' . 'logo_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                try {
                    $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                        'visibility' => 'public'
                    ]);

                    if (!$uploaded) {
                        throw new Exception('Storage::put returned false');
                    }

                    $updateData['logo'] = $filename;
                } catch (Exception $e) {
                    throw new Exception('Google Cloud Storage upload failed: ' . $e->getMessage());
                }
            }

            DB::table('keu_setting')
                ->where('username', $username)
                ->where('publish', '1')
                ->where('active', '1')
                ->update($updateData);

            $updatedSetting = DB::table('keu_setting')
                ->select('username', 'name', 'pengurus', 'logo', 'publish', 'active')
                ->where('username', $username)
                ->where('publish', '1')
                ->where('active', '1')
                ->first();

            $logoUrl = null;
            if ($updatedSetting->logo) {
                $logoUrl = GoogleCloudStorageHelper::getFileUrl($updatedSetting->logo);
            }

            return response()->json([
                'success' => true,
                'message' => 'Setting updated successfully',
                'data' => [
                    'setting' => [
                        'username' => $updatedSetting->username,
                        'name' => $updatedSetting->name,
                        'pengurus' => $updatedSetting->pengurus,
                        'logo' => $logoUrl,
                        'logo_path' => $updatedSetting->logo,
                        'publish' => $updatedSetting->publish,
                        'active' => $updatedSetting->active
                    ]
                ]
            ], 200);

        } catch (Exception $e) {
            \Log::error('Setting update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update setting: ' . $e->getMessage()
            ], 500);
        }
    }

}
