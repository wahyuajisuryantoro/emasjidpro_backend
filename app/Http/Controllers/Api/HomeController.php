<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function getHomeData(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $currentYear = Carbon::now()->year;
            $currentMonth = Carbon::now()->month;
            $lastMonth = Carbon::now()->subMonth()->month;
            $lastMonthYear = Carbon::now()->subMonth()->year;
            $pendapatanBulanIni = DB::table('keu_transaction')
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('account_category', '5')
                ->where('publish', '1')
                ->whereYear('date_transaction', $currentYear)
                ->whereMonth('date_transaction', $currentMonth)
                ->sum('value');

            $pendapatanBulanLalu = DB::table('keu_transaction')
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('account_category', '5')
                ->where('publish', '1')
                ->whereYear('date_transaction', $lastMonthYear)
                ->whereMonth('date_transaction', $lastMonth)
                ->sum('value');

            $pengeluaranBulanIni = DB::table('keu_transaction')
                ->where('username', $username)
                ->where('status', 'credit')
                ->where('account_category', '6')
                ->where('publish', '1')
                ->whereYear('date_transaction', $currentYear)
                ->whereMonth('date_transaction', $currentMonth)
                ->sum('value');

            $pengeluaranBulanLalu = DB::table('keu_transaction')
                ->where('username', $username)
                ->where('status', 'credit')
                ->where('account_category', '6')
                ->where('publish', '1')
                ->whereYear('date_transaction', $lastMonthYear)
                ->whereMonth('date_transaction', $lastMonth)
                ->sum('value');

            return response()->json([
                'success' => true,
                'data' => [
                    'pendapatan' => [
                        'bulan_ini' => 'Rp ' . number_format($pendapatanBulanIni, 0, ',', '.'),
                        'bulan_lalu' => 'Rp ' . number_format($pendapatanBulanLalu, 0, ',', '.')
                    ],
                    'pengeluaran' => [
                        'bulan_ini' => 'Rp ' . number_format($pengeluaranBulanIni, 0, ',', '.'),
                        'bulan_lalu' => 'Rp ' . number_format($pengeluaranBulanLalu, 0, ',', '.')
                    ],
                    
                ],
                'message' => 'Data home berhasil dimuat'
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data home: ' . $e->getMessage()
            ], 500);
        }
    }
}
