<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function getDataMasjid()
    {
        try {
            $username = Auth::user()->username;

            $masjid = DB::table('masjid')
                ->where('username', $username)
                ->first();

            if ($masjid) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Data masjid berhasil dimuat',
                    'masjid' => [
                        'no' => $masjid->no,
                        'username' => $masjid->username,
                        'subdomain' => $masjid->subdomain,
                        'name' => $masjid->name,
                        'link' => $masjid->link,
                        'content' => $masjid->content,
                        'address' => $masjid->address,
                        'city' => $masjid->city,
                        'maps' => $masjid->maps,
                        'phone' => $masjid->phone,
                        'email' => $masjid->email,
                        'luas_tanah' => $masjid->luas_tanah,
                        'luas_bangunan' => $masjid->luas_bangunan,
                        'status_tanah' => $masjid->status_tanah,
                        'tahun_berdiri' => $masjid->tahun_berdiri,
                        'legalitas' => $masjid->legalitas,
                        'facebook' => $masjid->facebook,
                        'twitter' => $masjid->twitter,
                        'instagram' => $masjid->instagram,
                        'youtube' => $masjid->youtube,
                        'tiktok' => $masjid->tiktok,
                        'picture' => $masjid->picture,
                        'date' => $masjid->date,
                        'publish' => $masjid->publish,
                    ]
                ], 200);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data masjid tidak ditemukan untuk user ini'
                ], 404);
            }
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memuat data masjid: ' . $e->getMessage()
            ], 500);
        }
    }
}
