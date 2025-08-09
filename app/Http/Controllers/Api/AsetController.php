<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Helpers\GoogleCloudStorageHelper;
use Illuminate\Support\Facades\Validator;

class AsetController extends Controller
{
    public function getDashboardAset(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $totalNilaiAset = DB::table('keu_asset')
                ->where('username', $username)
                ->where('publish', '1')
                ->sum('value');

            $totalPenyusutan = DB::table('keu_asset_depreciation')
                ->where('username', $username)
                ->where('publish', '1')
                ->sum('value');


            $nilaiAsetSaatIni = $totalNilaiAset - $totalPenyusutan;

            $categoryAset = DB::table('keu_asset_category as ac')
                ->leftJoin('keu_asset as a', 'ac.no', '=', 'a.no_category')
                ->selectRaw('ac.name, SUM(a.value - a.depreciation) as total_value')
                ->where('ac.username', $username)
                ->where('ac.publish', '1')
                ->where('a.username', $username)
                ->where('a.publish', '1')
                ->groupBy('ac.no', 'ac.name')
                ->get()
                ->map(function ($category) {
                    $category->formatted_total_value = 'Rp ' . number_format($category->total_value, 0, ',', '.');
                    return $category;
                });

            $asetList = DB::table('keu_asset')
                ->select('no', 'name', 'date_purchase', 'value')
                ->where('username', $username)
                ->where('publish', '1')
                ->orderBy('date_transaction', 'desc')
                ->limit(7)
                ->get()
                ->map(function ($aset) {
                    $aset->formatted_value = 'Rp ' . number_format($aset->value, 0, ',', '.');
                    return $aset;
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'total_nilai_aset' => $totalNilaiAset,
                    'formatted_total_nilai_aset' => 'Rp ' . number_format($totalNilaiAset, 0, ',', '.'),
                    'total_penyusutan' => $totalPenyusutan,
                    'formatted_total_penyusutan' => 'Rp ' . number_format($totalPenyusutan, 0, ',', '.'),
                    'nilai_aset_saat_ini' => $nilaiAsetSaatIni,
                    'formatted_nilai_aset_saat_ini' => 'Rp ' . number_format($nilaiAsetSaatIni, 0, ',', '.'),
                    'category_aset' => $categoryAset,
                    'aset_list' => $asetList
                ],
                'message' => 'Dashboard aset berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function getDetailAset($no)
    {
        try {
            $username = auth()->user()->username;
            $aset = DB::table('keu_asset as a')
                ->leftJoin('keu_asset_category as ac', 'a.no_category', '=', 'ac.no')
                ->leftJoin('keu_account_category as acc', 'a.account_category', '=', 'acc.code')
                ->leftJoin('keu_account as ar', function ($join) use ($username) {
                    $join->on('a.account_related', '=', 'ar.code')
                        ->where('ar.username', '=', $username);
                })
                ->select(
                    'a.*',
                    'ac.name as category_name',
                    'acc.name as account_category_name',
                    'ar.name as account_related_name'
                )
                ->where('a.no', $no)
                ->where('a.username', $username)
                ->where('a.publish', '1')
                ->first();

            if (!$aset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            $statusText = $aset->status === 'debit' ? 'Aktif' : 'Tidak Aktif';

            // Generate picture URL if exists
            $pictureUrl = null;
            if ($aset->picture) {
                $pictureUrl = GoogleCloudStorageHelper::getFileUrl($aset->picture);
            }

            $asetData = [
                'id' => $aset->no,
                'name' => $aset->name,
                'category' => $aset->category_name,
                'brand' => $aset->brand,
                'vendor' => $aset->vendor,
                'description' => $aset->description,
                'value' => $aset->value,
                'formatted_value' => 'Rp ' . number_format($aset->value, 0, ',', '.'),
                'depreciation' => $aset->depreciation,
                'formatted_depreciation' => 'Rp ' . number_format($aset->depreciation, 0, ',', '.'),
                'location' => $aset->location,
                'economic_life' => $aset->economic_life,
                'date_purchase' => $aset->date_purchase,
                'date_transaction' => $aset->date_transaction,
                'status' => $statusText,
                'picture' => $pictureUrl,
                'picture_path' => $aset->picture,
                'attachment' => $aset->attachment,

                'account_info' => [
                    'account_category' => $aset->account_category_name,
                    'purchased_with' => $aset->account_related_name,
                    'account_category_code' => $aset->account_category,
                    'account_related_code' => $aset->account_related,
                ],

                'purchaseInfo' => [
                    'date' => $aset->date_purchase,
                    'price' => $aset->value,
                    'seller' => $aset->vendor,
                    'purchased_with_account' => $aset->account_related_name,
                ],

                'documents' => $aset->attachment ? [$aset->attachment] : [],
            ];

            return response()->json([
                'success' => true,
                'data' => $asetData,
                'message' => 'Detail aset berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function getAllAset(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $assets = DB::table('keu_asset as a')
                ->leftJoin('keu_asset_category as ac', 'a.no_category', '=', 'ac.no')
                ->leftJoin('keu_account as ar', function ($join) use ($username) {
                    $join->on('a.account_related', '=', 'ar.code')
                        ->where('ar.username', '=', $username);
                })
                ->select(
                    'a.no',
                    'a.name',
                    'a.date_purchase',
                    'ac.name as category_name',
                    'ar.name as account_name',
                    'a.value',
                    'a.depreciation'
                )
                ->where('a.username', $username)
                ->where('a.publish', '1')
                ->orderBy('a.date_purchase', 'desc')
                ->get()
                ->map(function ($asset) {
                    return [
                        'no' => $asset->no,
                        'name' => $asset->name,
                        'date_purchase' => $asset->date_purchase,
                        'category' => $asset->category_name ?? 'Tidak Ada Kategori',
                        'purchased_with' => $asset->account_name ?? 'Tidak Diketahui',
                        'value' => $asset->value,
                        'formatted_value' => 'Rp ' . number_format($asset->value, 0, ',', '.'),
                        'current_value' => $asset->value - $asset->depreciation,
                        'formatted_current_value' => 'Rp ' . number_format($asset->value - $asset->depreciation, 0, ',', '.')
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $assets,
                'total_assets' => $assets->count(),
                'message' => 'Semua aset berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat semua aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getAsetList()
    {
        try {
            $username = auth()->user()->username;

            $asetList = DB::table('keu_asset')
                ->select('no as id', 'name', 'value')
                ->where('username', $username)
                ->where('publish', '1')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $asetList,
                'message' => 'List aset berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat list aset: ' . $e->getMessage()
            ], 500);
        }
    }

    public function buyAset(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $categoryExists = DB::table('keu_asset_category')
                ->where('no', $request->no_category)
                ->where('username', $username)
                ->where('publish', '1')
                ->exists();

            if (!$categoryExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori aset tidak ditemukan atau tidak valid'
                ], 404);
            }

            $accountExists = DB::table('keu_account')
                ->where('code', $request->account_related)
                ->where('username', $username)
                ->where('publish', '1')
                ->exists();

            if (!$accountExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun pembayaran tidak ditemukan atau tidak valid'
                ], 404);
            }

            DB::beginTransaction();

            try {
                $lastAsset = DB::table('keu_asset')
                    ->where('username', $username)
                    ->orderBy('no', 'desc')
                    ->first();

                $nextNumber = 1;
                if ($lastAsset && $lastAsset->code) {
                    $lastNumber = (int) substr($lastAsset->code, 3);
                    $nextNumber = $lastNumber + 1;
                }
                $assetCode = 'AST' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
                $picturePath = '';
                if ($request->hasFile('picture')) {
                    $file = $request->file('picture');
                    $filename = 'aset/' . $username . '/' . $assetCode . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                    $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                        'visibility' => 'public'
                    ]);

                    if ($uploaded) {
                        $picturePath = $filename;
                    }
                }

                $assetData = [
                    'code' => $assetCode,
                    'user' => 'admin',
                    'username' => $username,
                    'subdomain' => $request->subdomain ?? '',
                    'no_category' => $request->no_category,
                    'status' => 'debit',
                    'account_category' => $request->account_category,
                    'account' => '1',
                    'account_related' => $request->account_related,
                    'name' => $request->name,
                    'brand' => $request->brand ?? '',
                    'vendor' => $request->vendor ?? '',
                    'link' => $request->link ?? '',
                    'description' => $request->description ?? '',
                    'value' => $request->value,
                    'depreciation' => 0,
                    'location' => $request->location ?? '',
                    'economic_life' => 2000,
                    'date_purchase' => $request->date_purchase,
                    'date_transaction' => now(),
                    'picture' => $picturePath,
                    'attachment' => $request->attachment ?? '',
                    'publish' => '1'
                ];

                $assetId = DB::table('keu_asset')->insertGetId($assetData);

                $account = DB::table('keu_account')
                    ->where('code', $request->account_related)
                    ->where('username', $username)
                    ->first();

                if ($account && $account->balance < $request->value) {
                    throw new \Exception('Saldo tidak mencukupi untuk pembelian aset ini');
                }

                DB::table('keu_account')
                    ->where('code', $request->account_related)
                    ->where('username', $username)
                    ->decrement('balance', $request->value);

                DB::commit();

                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Aset Baru Berhasil Dibeli',
                    'message' => sprintf(
                        'Aset "%s" sebesar %s berhasil dibeli pada %s. Kode aset: %s',
                        $request->name,
                        'Rp ' . number_format($request->value, 0, ',', '.'),
                        Carbon::parse($request->date_purchase)->locale('id')->translatedFormat('d F Y'),
                        $assetCode
                    ),
                    'is_read' => '0',
                    'icon' => 'shopping_cart_2_line',
                    'priority' => $request->value >= 5000000 ? 'high' : 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                $newAsset = DB::table('keu_asset as a')
                    ->leftJoin('keu_asset_category as ac', 'a.no_category', '=', 'ac.no')
                    ->leftJoin('keu_account as ar', function ($join) use ($username) {
                        $join->on('a.account_related', '=', 'ar.code')
                            ->where('ar.username', '=', $username);
                    })
                    ->select(
                        'a.*',
                        'ac.name as category_name',
                        'ar.name as account_name'
                    )
                    ->where('a.no', $assetId)
                    ->first();

                $pictureUrl = null;
                if ($picturePath) {
                    $pictureUrl = GoogleCloudStorageHelper::getFileUrl($picturePath);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Aset berhasil dibeli dan ditambahkan',
                    'data' => [
                        'id' => $newAsset->no,
                        'code' => $newAsset->code,
                        'name' => $newAsset->name,
                        'category' => $newAsset->category_name,
                        'brand' => $newAsset->brand,
                        'vendor' => $newAsset->vendor,
                        'value' => $newAsset->value,
                        'formatted_value' => 'Rp ' . number_format($newAsset->value, 0, ',', '.'),
                        'depreciation' => $newAsset->depreciation,
                        'formatted_depreciation' => 'Rp ' . number_format($newAsset->depreciation, 0, ',', '.'),
                        'location' => $newAsset->location,
                        'economic_life' => $newAsset->economic_life,
                        'date_purchase' => $newAsset->date_purchase,
                        'purchased_with' => $newAsset->account_name,
                        'date_created' => $newAsset->date_transaction,
                        'picture' => $pictureUrl,
                        'picture_path' => $picturePath
                    ]
                ], 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membeli aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function sellAset(Request $request, $no)
    {
        try {
            $username = auth()->user()->username;

            $aset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$aset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            DB::beginTransaction();

            try {
                DB::table('keu_asset_out')->insert([
                    'code' => 'ASO' . str_pad(DB::table('keu_asset_out')->count() + 1, 3, '0', STR_PAD_LEFT),
                    'code_asset' => $aset->code,
                    'user' => $aset->user,
                    'account_category' => $aset->account_category,
                    'account' => $aset->account,
                    'account_related' => $aset->account_related,
                    'name' => $aset->name,
                    'link' => $aset->link,
                    'description' => $request->description ?? 'Penjualan aset: ' . $aset->name,
                    'value' => $request->sell_value,
                    'sell_to' => $request->sell_to,
                    'date_sell' => $request->date_sell,
                    'date' => now(),
                    'publish' => '1'
                ]);

                DB::table('keu_asset')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'publish' => '0'
                    ]);

                DB::commit();

                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Aset Berhasil Dijual',
                    'message' => sprintf(
                        'Aset "%s" berhasil dijual kepada "%s" dengan harga %s pada %s',
                        $aset->name,
                        $request->sell_to,
                        'Rp ' . number_format($request->sell_value, 0, ',', '.'),
                        Carbon::parse($request->date_sell)->locale('id')->translatedFormat('d F Y')
                    ),
                    'is_read' => '0',
                    'icon' => 'money_dollar_circle_line',
                    'priority' => $request->sell_value >= 5000000 ? 'high' : 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Aset berhasil dijual',
                    'data' => [
                        'asset_name' => $aset->name,
                        'sell_value' => $request->sell_value,
                        'formatted_sell_value' => 'Rp ' . number_format($request->sell_value, 0, ',', '.'),
                        'sell_to' => $request->sell_to,
                        'date_sell' => $request->date_sell,
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menjual aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function updateAset(Request $request, $no)
    {
        try {
            $username = auth()->user()->username;

            $existingAsset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$existingAsset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            $categoryExists = DB::table('keu_asset_category')
                ->where('no', $request->no_category)
                ->where('username', $username)
                ->where('publish', '1')
                ->exists();

            if (!$categoryExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori aset tidak ditemukan atau tidak valid'
                ], 404);
            }

            if ($request->account_related) {
                $accountExists = DB::table('keu_account')
                    ->where('code', $request->account_related)
                    ->where('username', $username)
                    ->where('publish', '1')
                    ->exists();

                if (!$accountExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Akun terkait tidak ditemukan atau tidak valid'
                    ], 404);
                }
            }

            DB::beginTransaction();

            try {
                // Hitung depreciation berdasarkan rumus: nilai aset / masa manfaat
                $newDepreciation = $request->value / $request->economic_life;

                $updateData = [
                    'no_category' => $request->no_category,
                    'name' => $request->name,
                    'brand' => $request->brand ?? '',
                    'vendor' => $request->vendor ?? '',
                    'description' => $request->description ?? '',
                    'value' => $request->value,
                    'depreciation' => $newDepreciation,
                    'location' => $request->location ?? '',
                    'economic_life' => $request->economic_life,
                    'date_purchase' => $request->date_purchase,
                    'picture' => $request->picture ?? $existingAsset->picture,
                    'attachment' => $request->attachment ?? $existingAsset->attachment,
                    'link' => $request->link ?? $existingAsset->link
                ];

                if ($request->account_category) {
                    $updateData['account_category'] = $request->account_category;
                }
                if ($request->account_related) {
                    $updateData['account_related'] = $request->account_related;
                }

                DB::table('keu_asset')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update($updateData);

                DB::commit();

                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Aset Berhasil Diperbarui',
                    'message' => sprintf(
                        'Aset "%s" berhasil diperbarui. Nilai aset: %s',
                        $request->name,
                        'Rp ' . number_format($request->value, 0, ',', '.')
                    ),
                    'is_read' => '0',
                    'icon' => 'edit_2_line',
                    'priority' => 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                $updatedAsset = DB::table('keu_asset as a')
                    ->leftJoin('keu_asset_category as ac', 'a.no_category', '=', 'ac.no')
                    ->leftJoin('keu_account_category as acc', 'a.account_category', '=', 'acc.code')
                    ->leftJoin('keu_account as ar', function ($join) use ($username) {
                        $join->on('a.account_related', '=', 'ar.code')
                            ->where('ar.username', '=', $username);
                    })
                    ->select(
                        'a.*',
                        'ac.name as category_name',
                        'acc.name as account_category_name',
                        'ar.name as account_related_name'
                    )
                    ->where('a.no', $no)
                    ->first();

                $statusText = $updatedAsset->status === 'debit' ? 'Aktif' : 'Tidak Aktif';

                $responseData = [
                    'id' => $updatedAsset->no,
                    'code' => $updatedAsset->code,
                    'name' => $updatedAsset->name,
                    'category' => $updatedAsset->category_name,
                    'brand' => $updatedAsset->brand,
                    'vendor' => $updatedAsset->vendor,
                    'description' => $updatedAsset->description,
                    'value' => $updatedAsset->value,
                    'formatted_value' => 'Rp ' . number_format($updatedAsset->value, 0, ',', '.'),
                    'depreciation' => $updatedAsset->depreciation,
                    'formatted_depreciation' => 'Rp ' . number_format($updatedAsset->depreciation, 0, ',', '.'),
                    'current_value' => $updatedAsset->value - $updatedAsset->depreciation,
                    'formatted_current_value' => 'Rp ' . number_format($updatedAsset->value - $updatedAsset->depreciation, 0, ',', '.'),
                    'location' => $updatedAsset->location,
                    'economic_life' => $updatedAsset->economic_life,
                    'date_purchase' => $updatedAsset->date_purchase,
                    'date_transaction' => $updatedAsset->date_transaction,
                    'status' => $statusText,
                    'picture' => $updatedAsset->picture,
                    'attachment' => $updatedAsset->attachment,
                    'account_info' => [
                        'account_category' => $updatedAsset->account_category_name,
                        'purchased_with' => $updatedAsset->account_related_name,
                        'account_category_code' => $updatedAsset->account_category,
                        'account_related_code' => $updatedAsset->account_related,
                    ]
                ];

                return response()->json([
                    'success' => true,
                    'data' => $responseData,
                    'message' => 'Aset berhasil diperbarui'
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function getAllDataSellAset(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $soldAssets = DB::table('keu_asset_out as ao')
                ->leftJoin('keu_account as ar', function ($join) use ($username) {
                    $join->on('ao.account_related', '=', 'ar.code')
                        ->where('ar.username', '=', $username);
                })
                ->select(
                    'ao.no',
                    'ao.code',
                    'ao.code_asset',
                    'ao.name',
                    'ao.description',
                    'ao.value as sell_value',
                    'ao.sell_to',
                    'ao.date_sell',
                    'ao.date as date_transaction',
                    'ar.name as account_name'
                )
                ->where('ao.publish', '1')
                ->orderBy('ao.date_sell', 'desc')
                ->get()
                ->map(function ($asset) {
                    return [
                        'no' => $asset->no,
                        'code' => $asset->code,
                        'code_asset' => $asset->code_asset,
                        'name' => $asset->name,
                        'description' => $asset->description,
                        'sell_value' => $asset->sell_value,
                        'formatted_sell_value' => 'Rp ' . number_format($asset->sell_value, 0, ',', '.'),
                        'sell_to' => $asset->sell_to,
                        'date_sell' => $asset->date_sell,
                        'date_transaction' => $asset->date_transaction,
                        'account_name' => $asset->account_name ?? 'Tidak Diketahui',
                    ];
                });

            $totalSellValue = $soldAssets->sum('sell_value');

            return response()->json([
                'success' => true,
                'data' => $soldAssets,
                'summary' => [
                    'total_sold_assets' => $soldAssets->count(),
                    'total_sell_value' => $totalSellValue,
                    'formatted_total_sell_value' => 'Rp ' . number_format($totalSellValue, 0, ',', '.'),
                ],
                'message' => 'Data aset terjual berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data aset terjual: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function getAssetCategories()
    {
        try {
            $username = auth()->user()->username;

            $categories = DB::table('keu_asset_category')
                ->select('no as id', 'name', 'description')
                ->where('username', $username)
                ->where('publish', '1')
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories,
                'message' => 'Kategori aset berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat kategori aset: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getPaymentAccounts()
    {
        try {
            $username = auth()->user()->username;
            $allowedAccountCodes = [
                '101',
                '102',
                '103',
                '104',
            ];

            $accounts = DB::table('keu_account')
                ->select('code', 'name', 'balance', 'type', 'cash_and_bank')
                ->where('username', $username)
                ->where('publish', '1')
                ->whereIn('code', $allowedAccountCodes)
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($account) {
                    $account->formatted_balance = 'Rp ' . number_format($account->balance, 0, ',', '.');
                    switch ($account->code) {
                        case '101':
                            $account->account_type = 'Kas';
                            break;
                        case '102':
                        case '103':
                            $account->account_type = 'Bank';
                            break;
                        case '104':
                            $account->account_type = 'Piutang';
                            break;
                        default:
                            $account->account_type = 'Lainnya';
                    }

                    return $account;
                });

            return response()->json([
                'success' => true,
                'data' => $accounts,
                'message' => 'Akun pembayaran berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat akun pembayaran: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getCategoryAset($no)
    {
        try {
            $username = auth()->user()->username;

            $category = DB::table('keu_asset_category')
                ->select('no as id', 'name', 'description')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori tidak ditemukan'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $category,
                'message' => 'Detail kategori berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail kategori: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function addCategoryAset(Request $request)
    {
        try {
            $username = auth()->user()->username;
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|min:3|max:100',
                'description' => 'nullable|string|max:255'
            ], [
                'name.required' => 'Nama kategori harus diisi',
                'name.min' => 'Nama kategori minimal 3 karakter',
                'name.max' => 'Nama kategori maksimal 100 karakter',
                'description.max' => 'Deskripsi maksimal 255 karakter'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $existingCategory = DB::table('keu_asset_category')
                ->where('username', $username)
                ->where('name', $request->name)
                ->where('publish', '1')
                ->first();

            if ($existingCategory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori dengan nama tersebut sudah ada'
                ], 409);
            }

            DB::beginTransaction();

            try {
                $categoryId = DB::table('keu_asset_category')->insertGetId([
                    'username' => $username,
                    'subdomain' => $request->subdomain ?? '',
                    'name' => $request->name,
                    'description' => $request->description ?? '',
                    'publish' => '1'
                ]);

                DB::commit();

                $newCategory = DB::table('keu_asset_category')
                    ->select('no as id', 'name', 'description')
                    ->where('no', $categoryId)
                    ->first();

                return response()->json([
                    'success' => true,
                    'data' => $newCategory,
                    'message' => 'Kategori aset berhasil ditambahkan'
                ], 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan kategori aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function updateCategoryAset(Request $request, $no)
    {
        try {
            $username = auth()->user()->username;

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|min:3|max:100',
                'description' => 'nullable|string|max:255'
            ], [
                'name.required' => 'Nama kategori harus diisi',
                'name.min' => 'Nama kategori minimal 3 karakter',
                'name.max' => 'Nama kategori maksimal 100 karakter',
                'description.max' => 'Deskripsi maksimal 255 karakter'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category = DB::table('keu_asset_category')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori tidak ditemukan'
                ], 404);
            }

            $existingCategory = DB::table('keu_asset_category')
                ->where('username', $username)
                ->where('name', $request->name)
                ->where('no', '!=', $no)
                ->where('publish', '1')
                ->first();

            if ($existingCategory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori dengan nama tersebut sudah ada'
                ], 409);
            }

            DB::beginTransaction();

            try {

                DB::table('keu_asset_category')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'name' => $request->name,
                        'description' => $request->description ?? '',
                    ]);

                DB::commit();

                $updatedCategory = DB::table('keu_asset_category')
                    ->select('no as id', 'name', 'description')
                    ->where('no', $no)
                    ->first();

                return response()->json([
                    'success' => true,
                    'data' => $updatedCategory,
                    'message' => 'Kategori aset berhasil diperbarui'
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui kategori aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function deleteCategoryAset($no)
    {
        try {
            $username = auth()->user()->username;

            $category = DB::table('keu_asset_category')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kategori tidak ditemukan'
                ], 404);
            }
            $assetCount = DB::table('keu_asset')
                ->where('no_category', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->count();

            if ($assetCount > 0) {
                $assetNames = DB::table('keu_asset')
                    ->select('name')
                    ->where('no_category', $no)
                    ->where('username', $username)
                    ->where('publish', '1')
                    ->limit(3)
                    ->pluck('name')
                    ->toArray();

                $assetExamples = implode(', ', $assetNames);
                if ($assetCount > 3) {
                    $assetExamples .= ' dan ' . ($assetCount - 3) . ' aset lainnya';
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Kategori tidak dapat dihapus karena masih digunakan oleh ' . $assetCount . ' aset',
                    'details' => [
                        'asset_count' => $assetCount,
                        'asset_examples' => $assetExamples,
                        'suggestion' => 'Pindahkan atau hapus aset yang menggunakan kategori ini terlebih dahulu'
                    ]
                ], 409);
            }

            DB::beginTransaction();

            try {
                DB::table('keu_asset_category')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Kategori aset berhasil dihapus',
                    'data' => [
                        'deleted_category' => [
                            'id' => $category->no,
                            'name' => $category->name,
                            'description' => $category->description
                        ]
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus kategori aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getAssetDepreciation($no)
    {
        try {
            $username = auth()->user()->username;

            $asset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            $depreciations = DB::table('keu_asset_depreciation as d')
                ->leftJoin('keu_asset as a', 'd.code_asset', '=', 'a.code')
                ->select(
                    'd.no',
                    'd.name as depreciation_name',
                    'd.description',
                    'd.value',
                    'd.date_transaction',
                    'a.name as asset_name'
                )
                ->where('d.code_asset', $asset->code)
                ->where('d.username', $username)
                ->where('d.publish', '1')
                ->orderBy('d.date_transaction', 'desc')
                ->get()
                ->map(function ($depreciation) {
                    return [
                        'no' => $depreciation->no,
                        'asset_name' => $depreciation->asset_name,
                        'depreciation_name' => $depreciation->depreciation_name,
                        'description' => $depreciation->description,
                        'value' => $depreciation->value,
                        'formatted_value' => 'Rp ' . number_format($depreciation->value, 0, ',', '.'),
                        'date_transaction' => $depreciation->date_transaction,
                        'formatted_date' => Carbon::parse($depreciation->date_transaction)->locale('id')->translatedFormat('d F Y')
                    ];
                });

            $totalDepreciationValue = $depreciations->sum('value');

            return response()->json([
                'success' => true,
                'data' => $depreciations,
                'total_depreciation' => $totalDepreciationValue,
                'formatted_total_depreciation' => 'Rp ' . number_format($totalDepreciationValue, 0, ',', '.'),
                'message' => 'Riwayat penyusutan aset berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat riwayat penyusutan aset: ' . $e->getMessage()
            ], 500);
        }
    }

    public function addAssetDepreciation(Request $request)
    {
        try {
            $username = auth()->user()->username;
            $asset = DB::table('keu_asset')
                ->where('no', $request->asset_no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            DB::beginTransaction();

            try {
                $lastDepreciation = DB::table('keu_asset_depreciation')
                    ->where('username', $username)
                    ->orderBy('no', 'desc')
                    ->first();

                $nextNumber = 1;
                if ($lastDepreciation && $lastDepreciation->code) {
                    $lastNumber = (int) substr($lastDepreciation->code, 3);
                    $nextNumber = $lastNumber + 1;
                }
                $depreciationCode = 'DEP' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

                $depreciationId = DB::table('keu_asset_depreciation')->insertGetId([
                    'code' => $depreciationCode,
                    'code_asset' => $asset->code,
                    'username' => $username,
                    'subdomain' => $request->subdomain ?? '',
                    'account_category' => $asset->account_category,
                    'account' => $asset->account,
                    'account_related' => $asset->account_related,
                    'name' => $request->name,
                    'link' => $request->link ?? '',
                    'description' => $request->description ?? '',
                    'value' => $request->value,
                    'date_transaction' => $request->date_transaction,
                    'date' => now(),
                    'publish' => '1'
                ]);
                DB::table('keu_asset')
                    ->where('no', $request->asset_no)
                    ->where('username', $username)
                    ->increment('depreciation', $request->value);

                DB::commit();
                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Penyusutan Aset Ditambahkan',
                    'message' => sprintf(
                        'Penyusutan "%s" untuk aset "%s" sebesar %s berhasil ditambahkan',
                        $request->name,
                        $asset->name,
                        'Rp ' . number_format($request->value, 0, ',', '.')
                    ),
                    'is_read' => '0',
                    'icon' => 'line_chart_line',
                    'priority' => 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Data penyusutan berhasil ditambahkan',
                    'data' => [
                        'id' => $depreciationId,
                        'code' => $depreciationCode,
                        'asset_name' => $asset->name,
                        'depreciation_name' => $request->name,
                        'description' => $request->description ?? '',
                        'value' => $request->value,
                        'formatted_value' => 'Rp ' . number_format($request->value, 0, ',', '.'),
                        'date_transaction' => $request->date_transaction,
                        'formatted_date' => Carbon::parse($request->date_transaction)->locale('id')->translatedFormat('d F Y')
                    ]
                ], 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan data penyusutan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function editAssetDepreciation(Request $request, $no)
    {
        try {
            $username = auth()->user()->username;
            $depreciation = DB::table('keu_asset_depreciation')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$depreciation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data penyusutan tidak ditemukan'
                ], 404);
            }
            $asset = DB::table('keu_asset')
                ->where('code', $depreciation->code_asset)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset terkait tidak ditemukan'
                ], 404);
            }

            DB::beginTransaction();

            try {
                $oldValue = $depreciation->value;
                $newValue = $request->value;
                $valueDifference = $newValue - $oldValue;
                DB::table('keu_asset_depreciation')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'name' => $request->name,
                        'description' => $request->description ?? '',
                        'value' => $request->value,
                        'date_transaction' => $request->date_transaction,
                    ]);
                DB::table('keu_asset')
                    ->where('code', $depreciation->code_asset)
                    ->where('username', $username)
                    ->increment('depreciation', $valueDifference);

                DB::commit();
                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Penyusutan Aset Diperbarui',
                    'message' => sprintf(
                        'Penyusutan "%s" untuk aset "%s" berhasil diperbarui dengan nilai %s',
                        $request->name,
                        $asset->name,
                        'Rp ' . number_format($request->value, 0, ',', '.')
                    ),
                    'is_read' => '0',
                    'icon' => 'edit_2_line',
                    'priority' => 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Data penyusutan berhasil diperbarui',
                    'data' => [
                        'id' => $no,
                        'code' => $depreciation->code,
                        'asset_name' => $asset->name,
                        'depreciation_name' => $request->name,
                        'description' => $request->description ?? '',
                        'value' => $request->value,
                        'formatted_value' => 'Rp ' . number_format($request->value, 0, ',', '.'),
                        'date_transaction' => $request->date_transaction,
                        'formatted_date' => Carbon::parse($request->date_transaction)->locale('id')->translatedFormat('d F Y')
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui data penyusutan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteAssetDepreciation($no)
    {
        try {
            $username = auth()->user()->username;
            $depreciation = DB::table('keu_asset_depreciation')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$depreciation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data penyusutan tidak ditemukan'
                ], 404);
            }

            $asset = DB::table('keu_asset')
                ->where('code', $depreciation->code_asset)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            DB::beginTransaction();

            try {
                DB::table('keu_asset_depreciation')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->delete();

                if ($asset) {
                    DB::table('keu_asset')
                        ->where('code', $depreciation->code_asset)
                        ->where('username', $username)
                        ->decrement('depreciation', $depreciation->value);
                }
                DB::commit();
                if ($asset) {
                    DB::table('notifications')->insert([
                        'username' => $username,
                        'title' => 'Penyusutan Aset Dihapus',
                        'message' => sprintf(
                            'Penyusutan "%s" untuk aset "%s" sebesar %s berhasil dihapus',
                            $depreciation->name,
                            $asset->name,
                            'Rp ' . number_format($depreciation->value, 0, ',', '.')
                        ),
                        'is_read' => '0',
                        'icon' => 'delete_bin_line',
                        'priority' => 'normal',
                        'date' => now(),
                        'publish' => '1'
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Data penyusutan berhasil dihapus',
                    'data' => [
                        'deleted_depreciation' => [
                            'id' => $no,
                            'name' => $depreciation->name,
                            'value' => $depreciation->value,
                            'formatted_value' => 'Rp ' . number_format($depreciation->value, 0, ',', '.')
                        ]
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data penyusutan: ' . $e->getMessage()
            ], 500);
        }
    }


    // PICUTRE HANDLER
    public function uploadAssetPicture(Request $request, $no)
    {
        try {
            $username = auth()->user()->username;
            if (!$request->hasFile('picture')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gambar harus dipilih'
                ], 422);
            }

            $file = $request->file('picture');

            if (!$file->isValid() || !in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/jpg'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'File harus berupa gambar (jpeg, png, jpg)'
                ], 422);
            }

            $asset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            DB::beginTransaction();

            try {
                $filename = 'aset/' . $username . '/' . $asset->code . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if (!$uploaded) {
                    throw new \Exception('Upload gagal ke Google Cloud Storage');
                }

                if ($asset->picture) {
                    try {
                        Storage::disk('gcs')->delete($asset->picture);
                    } catch (\Exception $e) {
                        \Log::warning('Failed to delete old asset picture: ' . $e->getMessage());
                    }
                }

                DB::table('keu_asset')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'picture' => $filename
                    ]);

                DB::commit();

                $pictureUrl = GoogleCloudStorageHelper::getFileUrl($filename);
                return response()->json([
                    'success' => true,
                    'message' => 'Foto aset berhasil diupload',
                    'data' => [
                        'asset_id' => $asset->no,
                        'asset_name' => $asset->name,
                        'picture_url' => $pictureUrl,
                        'picture_path' => $filename
                    ]
                ], 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupload foto aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function updateAssetPicture(Request $request, $no)
    {
        try {
            $username = auth()->user()->username;
            if (!$request->hasFile('picture')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gambar harus dipilih'
                ], 422);
            }

            $file = $request->file('picture');
            if (!$file->isValid() || !in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/jpg'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'File harus berupa gambar (jpeg, png, jpg)'
                ], 422);
            }

            $asset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            DB::beginTransaction();

            try {
                if ($asset->picture) {
                    try {
                        Storage::disk('gcs')->delete($asset->picture);
                    } catch (\Exception $e) {
                        \Log::warning('Failed to delete old asset picture: ' . $e->getMessage());
                    }
                }
                $filename = 'aset/' . $username . '/' . $asset->code . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if (!$uploaded) {
                    throw new \Exception('Upload gagal ke Google Cloud Storage');
                }
                DB::table('keu_asset')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'picture' => $filename
                    ]);

                DB::commit();
                $pictureUrl = GoogleCloudStorageHelper::getFileUrl($filename);
                return response()->json([
                    'success' => true,
                    'message' => 'Foto aset berhasil diperbarui',
                    'data' => [
                        'picture_url' => $pictureUrl,
                        'picture_path' => $filename
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui foto aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function deleteAssetPicture($no)
    {
        try {
            $username = auth()->user()->username;

            $asset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            if (!$asset->picture) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak memiliki foto'
                ], 404);
            }

            DB::beginTransaction();

            try {
                try {
                    Storage::disk('gcs')->delete($asset->picture);
                } catch (\Exception $e) {
                    \Log::warning('Failed to delete asset picture from GCS: ' . $e->getMessage());

                }
                DB::table('keu_asset')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'picture' => ''
                    ]);

                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Foto aset berhasil dihapus',
                    'data' => [
                        'asset_id' => $asset->no,
                        'asset_name' => $asset->name
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus foto aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    // DOCUMENT HANDLER

    public function uploadAssetDocument(Request $request, $no)
    {
        try {
            $username = auth()->user()->username;

            if (!$request->hasFile('document')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen harus dipilih'
                ], 422);
            }

            $file = $request->file('document');

            $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png', 'image/jpg'];

            if (!$file->isValid() || !in_array($file->getMimeType(), $allowedTypes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File harus berupa PDF, DOC, DOCX, JPG, PNG, atau JPEG'
                ], 422);
            }

            if ($file->getSize() > 10 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ukuran file tidak boleh lebih dari 10MB'
                ], 422);
            }

            $asset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            DB::beginTransaction();

            try {

                $filename = 'aset_documents/' . $username . '/' . $asset->code . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if (!$uploaded) {
                    throw new \Exception('Upload gagal ke Google Cloud Storage');
                }

                $currentAttachment = $asset->attachment;
                $newAttachment = $currentAttachment ? $currentAttachment . ',' . $filename : $filename;

                DB::table('keu_asset')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'attachment' => $newAttachment
                    ]);

                DB::commit();

                $documentUrl = GoogleCloudStorageHelper::getFileUrl($filename);

                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Dokumen Aset Berhasil Diupload',
                    'message' => sprintf(
                        'Dokumen untuk aset "%s" berhasil diupload',
                        $asset->name
                    ),
                    'is_read' => '0',
                    'icon' => 'file_line',
                    'priority' => 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Dokumen aset berhasil diupload',
                    'data' => [
                        'asset_id' => $asset->no,
                        'asset_name' => $asset->name,
                        'document_url' => $documentUrl,
                        'document_path' => $filename,
                        'document_name' => $file->getClientOriginalName(),
                        'document_size' => $file->getSize()
                    ]
                ], 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupload dokumen aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function updateAssetDocument(Request $request, $no, $documentIndex)
    {
        try {
            $username = auth()->user()->username;

            if (!$request->hasFile('document')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen harus dipilih'
                ], 422);
            }

            $file = $request->file('document');

            $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png', 'image/jpg'];

            if (!$file->isValid() || !in_array($file->getMimeType(), $allowedTypes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File harus berupa PDF, DOC, DOCX, JPG, PNG, atau JPEG'
                ], 422);
            }

            if ($file->getSize() > 10 * 1024 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ukuran file tidak boleh lebih dari 10MB'
                ], 422);
            }

            $asset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            $attachments = $asset->attachment ? explode(',', $asset->attachment) : [];

            if (!isset($attachments[$documentIndex])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen tidak ditemukan'
                ], 404);
            }

            DB::beginTransaction();

            try {
                $filename = 'aset_documents/' . $username . '/' . $asset->code . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if (!$uploaded) {
                    throw new \Exception('Upload gagal ke Google Cloud Storage');
                }

                if ($attachments[$documentIndex]) {
                    try {
                        Storage::disk('gcs')->delete($attachments[$documentIndex]);
                    } catch (\Exception $e) {
                        \Log::warning('Failed to delete old asset document: ' . $e->getMessage());
                    }
                }

                $attachments[$documentIndex] = $filename;

                DB::table('keu_asset')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'attachment' => implode(',', $attachments)
                    ]);

                DB::commit();

                $documentUrl = GoogleCloudStorageHelper::getFileUrl($filename);

                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Dokumen Aset Berhasil Diperbarui',
                    'message' => sprintf(
                        'Dokumen untuk aset "%s" berhasil diperbarui',
                        $asset->name
                    ),
                    'is_read' => '0',
                    'icon' => 'file_line',
                    'priority' => 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Dokumen aset berhasil diperbarui',
                    'data' => [
                        'asset_id' => $asset->no,
                        'asset_name' => $asset->name,
                        'document_url' => $documentUrl,
                        'document_path' => $filename,
                        'document_name' => $file->getClientOriginalName(),
                        'document_size' => $file->getSize(),
                        'document_index' => $documentIndex
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui dokumen aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function deleteAssetDocument($no, $documentIndex)
    {
        try {
            $username = auth()->user()->username;

            $asset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            $attachments = $asset->attachment ? explode(',', $asset->attachment) : [];

            if (!isset($attachments[$documentIndex])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen tidak ditemukan'
                ], 404);
            }

            DB::beginTransaction();

            try {
                $documentPath = $attachments[$documentIndex];

                try {
                    Storage::disk('gcs')->delete($documentPath);
                } catch (\Exception $e) {
                   
                }

                unset($attachments[$documentIndex]);
                $attachments = array_values($attachments);
                DB::table('keu_asset')
                    ->where('no', $no)
                    ->where('username', $username)
                    ->update([
                        'attachment' => !empty($attachments) ? implode(',', $attachments) : ''
                    ]);

                DB::commit();

                // Add notification
                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Dokumen Aset Dihapus',
                    'message' => sprintf(
                        'Dokumen untuk aset "%s" berhasil dihapus',
                        $asset->name
                    ),
                    'is_read' => '0',
                    'icon' => 'file_line',
                    'priority' => 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Dokumen aset berhasil dihapus',
                    'data' => [
                        'asset_id' => $asset->no,
                        'asset_name' => $asset->name,
                        'deleted_document_index' => $documentIndex
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus dokumen aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getAssetDocuments($no)
    {
        try {
            $username = auth()->user()->username;

            $asset = DB::table('keu_asset')
                ->where('no', $no)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$asset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aset tidak ditemukan'
                ], 404);
            }

            // Parse attachments
            $attachments = $asset->attachment ? explode(',', $asset->attachment) : [];

            $documents = [];
            foreach ($attachments as $index => $attachment) {
                if (!empty($attachment)) {
                    $documents[] = [
                        'index' => $index,
                        'path' => $attachment,
                        'url' => GoogleCloudStorageHelper::getFileUrl($attachment),
                        'name' => basename($attachment),
                        'extension' => pathinfo($attachment, PATHINFO_EXTENSION)
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Dokumen aset berhasil dimuat',
                'data' => [
                    'asset_id' => $asset->no,
                    'asset_name' => $asset->name,
                    'documents' => $documents
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dokumen aset: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
}
