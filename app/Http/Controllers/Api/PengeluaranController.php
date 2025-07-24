<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\TransaksiModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Helpers\GoogleCloudStorageHelper;
use Illuminate\Support\Facades\Validator;

class PengeluaranController extends Controller
{
    public function getDashboardPengeluaran(Request $request)
    {
        try {

            $username = auth()->user()->username;

            $currentYear = Carbon::now()->year;
            $currentMonth = Carbon::now()->month;

            $pengeluaranBulanan = TransaksiModel::credit()
                ->published()
                ->where('username', $username)
                ->whereYear('date_transaction', $currentYear)
                ->whereMonth('date_transaction', $currentMonth)
                ->where('account_category', '6')
                ->sum('value');

            $pengeluaranTahunan = TransaksiModel::credit()
                ->published()
                ->where('username', $username)
                ->whereYear('date_transaction', $currentYear)
                ->where('account_category', '6')
                ->sum('value');

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('code_account_category', '6')
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $kategoriTransaksi = DB::table('keu_transaction')
                ->select(
                    'account',
                    DB::raw('SUM(value) as total_value')
                )
                ->where('username', $username)
                ->where('status', 'credit')
                ->where('account_category', '6')
                ->where('publish', '1')
                ->whereYear('date_transaction', $currentYear)
                ->groupBy('account')
                ->get();

            $kategoriFormatted = [];
            $totalPengeluaran = 0;

            foreach ($kategoriTransaksi as $kategori) {
                if (isset($accounts[$kategori->account])) {
                    $accountInfo = $accounts[$kategori->account];

                    $kategoriFormatted[] = [
                        'nama_kategori' => $accountInfo->name,
                        'kode_kategori' => $kategori->account,
                        'status' => 'credit',
                        'total_value' => $kategori->total_value
                    ];

                    $totalPengeluaran += $kategori->total_value;
                }
            }

            $riwayatTransaksi = TransaksiModel::credit()
                ->published()
                ->where('username', $username)
                ->where('account_category', '6')
                ->orderBy('date_transaction', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($transaksi) {
                    return [
                        'tanggal' => $transaksi->date_transaction->format('Y-m-d'),
                        'judul_transaksi' => $transaksi->name,
                        'amount' => $transaksi->value,
                        'formatted_amount' => 'Rp ' . number_format($transaksi->value, 0, ',', '.'),
                        'isIncome' => false
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'pengeluaran' => [
                        'bulanan' => $pengeluaranBulanan,
                        'formatted_bulanan' => 'Rp ' . number_format($pengeluaranBulanan, 0, ',', '.'),
                        'tahunan' => $pengeluaranTahunan,
                        'formatted_tahunan' => 'Rp ' . number_format($pengeluaranTahunan, 0, ',', '.')
                    ],
                    'ringkasan_transaksi' => [
                        'kategori_transaksi' => $kategoriFormatted,
                        'total_pengeluaran' => $totalPengeluaran,
                        'formatted_total_pengeluaran' => 'Rp ' . number_format($totalPengeluaran, 0, ',', '.')
                    ],

                    'riwayat_transaksi' => $riwayatTransaksi
                ],
                'message' => 'Dashboard pengeluaran berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard pengeluaran: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getRiwayatPengeluaran(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $period = $request->input('period', 'Bulan Ini');
            $category = $request->input('category', 'Semua');
            $sortKey = $request->input('sort_key', 'newest');
            $search = $request->input('search', '');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $query = TransaksiModel::where('keu_transaction.username', $username)
                ->where('keu_transaction.status', 'credit')
                ->where('keu_transaction.account_category', '6')
                ->where('keu_transaction.publish', '1');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('keu_transaction.name', 'like', "%{$search}%")
                        ->orWhere('keu_transaction.description', 'like', "%{$search}%");
                });
            }

            if ($category !== 'Semua') {
                $query->join('keu_account', function ($join) use ($username, $category) {
                    $join->on('keu_transaction.account', '=', 'keu_account.code')
                        ->where('keu_account.username', '=', $username)
                        ->where('keu_account.name', '=', $category);
                })
                    ->select('keu_transaction.*');
            }

            $now = Carbon::now();

            switch ($period) {
                case 'Hari Ini':
                    $query->whereDate('keu_transaction.date_transaction', $now->format('Y-m-d'));
                    break;

                case 'Minggu Ini':
                    $startOfWeek = $now->startOfWeek();
                    $endOfWeek = $now->endOfWeek();
                    $query->whereBetween('keu_transaction.date_transaction', [$startOfWeek, $endOfWeek]);
                    break;

                case 'Bulan Ini':
                    $query->whereYear('keu_transaction.date_transaction', $now->year)
                        ->whereMonth('keu_transaction.date_transaction', $now->month);
                    break;

                case 'Tahun Ini':
                    $query->whereYear('keu_transaction.date_transaction', $now->year);
                    break;

                case 'Semua Waktu':

                    break;

                default:
                    if ($startDate && $endDate) {
                        $query->whereBetween('keu_transaction.date_transaction', [
                            Carbon::parse($startDate),
                            Carbon::parse($endDate)->endOfDay()
                        ]);
                    }
                    break;
            }

            switch ($sortKey) {
                case 'newest':
                    $query->orderBy('keu_transaction.date_transaction', 'desc');
                    break;

                case 'oldest':
                    $query->orderBy('keu_transaction.date_transaction', 'asc');
                    break;

                case 'highest':
                    $query->orderBy('keu_transaction.value', 'desc');
                    break;

                case 'lowest':
                    $query->orderBy('keu_transaction.value', 'asc');
                    break;

                case 'category_asc':
                    $query->leftJoin('keu_account as account_sort', 'keu_transaction.account', '=', 'account_sort.code')
                        ->orderBy('account_sort.name', 'asc')
                        ->select('keu_transaction.*');
                    break;

                case 'category_desc':
                    $query->leftJoin('keu_account as account_sort', 'keu_transaction.account', '=', 'account_sort.code')
                        ->orderBy('account_sort.name', 'desc')
                        ->select('keu_transaction.*');
                    break;
            }

            $transactions = $query->get();

            $formattedTransactions = [];

            foreach ($transactions as $transaction) {
                $account = DB::table('keu_account')
                    ->where('username', $username)
                    ->where('code', $transaction->account)
                    ->first();

                $formattedTransactions[] = [
                    'id' => $transaction->no,
                    'title' => $transaction->name,
                    'description' => $transaction->description,
                    'date' => $transaction->date_transaction->format('d M Y'),
                    'amount' => $transaction->value,
                    'formatted_amount' => 'Rp ' . number_format($transaction->value, 0, ',', '.'),
                    'category' => $account ? $account->name : 'Tidak Terkategori',
                    'status' => 'credit',
                    'isIncome' => false,
                    'picture' => $transaction->picture,
                    'account_code' => $transaction->account,
                    'account_related_code' => $transaction->account_related,
                ];
            }

            $categories = ['Semua'];

            $bebanAccounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('code_account_category', '6')
                ->where('publish', '1')
                ->orderBy('name', 'asc')
                ->get();

            foreach ($bebanAccounts as $account) {
                $categories[] = $account->name;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'transactions' => $formattedTransactions,
                    'categories' => $categories,
                    'total_count' => count($formattedTransactions),
                    'total_amount' => array_sum(array_column($formattedTransactions, 'amount')),
                    'formatted_total_amount' => 'Rp ' . number_format(array_sum(array_column($formattedTransactions, 'amount')), 0, ',', '.'),
                ],
                'message' => 'Riwayat pengeluaran berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat riwayat pengeluaran: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function storeTransaksiPengeluaran(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_transaction' => 'required|date_format:Y-m-d H:i:s',
                'account' => 'required|string|max:10',
                'account_related' => 'required|string|max:10',
                'name' => 'required|string|max:50',
                'description' => 'nullable|string|max:200',
                'value' => 'required|numeric|min:1',
                'picture' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $username = auth()->user()->username;
            $userName = auth()->user()->name ?? 'system';
            $subdomain = $request->subdomain ?? '';

            $sourceAccount = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', $request->account)
                ->where('code', 'like', '6%')
                ->first();

            if (!$sourceAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun sumber tidak valid'
                ], 404);
            }

            $relatedAccount = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', $request->account_related)
                ->where('code', 'like', '1%')
                ->where('related', '1')
                ->first();

            if (!$relatedAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun tujuan tidak valid'
                ], 404);
            }

            $today = Carbon::now()->format('Ymd');
            $lastCode = DB::table('keu_transaction')
                ->where('username', $username)
                ->where('code', 'like', $today . '%')
                ->orderBy('code', 'desc')
                ->first();

            $sequence = '001';
            if ($lastCode) {
                $lastSequence = (int) substr($lastCode->code, -3);
                $sequence = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);
            }

            $transactionCode = $today . $sequence;

            $picturePath = '';
            if ($request->hasFile('picture')) {
                $file = $request->file('picture');
                $filename = 'pengeluaran/' . $username . '/' . $transactionCode . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if ($uploaded) {
                    $picturePath = $filename;
                }
            }

            DB::beginTransaction();

            try {
                $transactionId = DB::table('keu_transaction')->insertGetId([
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $transactionCode,
                    'user' => $userName,
                    'status' => 'credit',
                    'account_category' => '6',
                    'account' => $request->account,
                    'account_related' => $request->account_related,
                    'name' => $request->name,
                    'description' => $request->description ?? '',
                    'value' => $request->value,
                    'picture' => $picturePath,
                    'date_transaction' => $request->date_transaction,
                    'date' => now(),
                    'publish' => '1'
                ]);
                DB::table('keu_journal')->insert([
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $transactionCode,
                    'user' => $userName,
                    'status' => 'debit',
                    'account' => $request->account,
                    'name' => $sourceAccount->name,
                    'description' => $request->description ?? '',
                    'value' => $request->value,
                    'date_transaction' => Carbon::parse($request->date_transaction)->format('Y-m-d'),
                    'date' => now(),
                    'publish' => '1'
                ]);
                DB::table('keu_journal')->insert([
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $transactionCode,
                    'user' => $userName,
                    'status' => 'credit',
                    'account' => $request->account_related,
                    'name' => $relatedAccount->name,
                    'description' => $request->description ?? '',
                    'value' => $request->value,
                    'date_transaction' => Carbon::parse($request->date_transaction)->format('Y-m-d'),
                    'date' => now(),
                    'publish' => '1'
                ]);

                DB::commit();

                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Transaksi Pengeluaran Ditambahkan',
                    'message' => sprintf(
                        'Transaksi pengeluaran "%s" sebesar %s berhasil dicatat pada %s. Kode transaksi: %s',
                        $request->name,
                        'Rp ' . number_format($request->value, 0, ',', '.'),
                        Carbon::parse($request->date_transaction)->locale('id')->translatedFormat('d F Y H:i'),
                        $transactionCode
                    ),
                    'is_read' => '0',
                    'icon' => 'money_dollar_circle_line',
                    'priority' => $request->value >= 1000000 ? 'high' : 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                $transaction = DB::table('keu_transaction')
                    ->where('no', $transactionId)
                    ->first();
                $pictureUrl = null;
                if ($picturePath) {
                    $pictureUrl = GoogleCloudStorageHelper::getFileUrl($picturePath);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Transaksi pengeluaran berhasil ditambahkan',
                    'data' => [
                        'transaction' => [
                            'id' => $transaction->no,
                            'code' => $transaction->code,
                            'date_transaction' => $transaction->date_transaction,
                            'name' => $transaction->name,
                            'description' => $transaction->description,
                            'value' => $transaction->value,
                            'formatted_value' => 'Rp ' . number_format($transaction->value, 0, ',', '.'),
                            'account' => $transaction->account,
                            'account_name' => $sourceAccount->name,
                            'account_related' => $transaction->account_related,
                            'account_related_name' => $relatedAccount->name,
                            'picture' => $pictureUrl,
                            'picture_path' => $picturePath
                        ]
                    ]
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan transaksi pengeluaran: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getAccountsForPengeluaran()
    {
        try {

            $username = auth()->user()->username;

            $sourceAccounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', 'like', '6%')
                ->where('publish', '1')
                ->orderBy('code', 'asc')
                ->get()
                ->map(function ($account) {
                    return [
                        'code' => $account->code,
                        'name' => $account->code . ' - ' . $account->name,
                        'description' => $account->description
                    ];
                });

            $relatedAccounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', 'like', '1%')
                ->where('related', '1')
                ->where('publish', '1')
                ->orderBy('code', 'asc')
                ->get()
                ->map(function ($account) {
                    return [
                        'code' => $account->code,
                        'name' => $account->code . ' - ' . $account->name,
                        'description' => $account->description
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'source_accounts' => $sourceAccounts,
                    'related_accounts' => $relatedAccounts
                ],
                'message' => 'Data akun berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data akun: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getLaporanPengeluaranHarian()
    {
        try {
            $username = auth()->user()->username;
            $targetDate = Carbon::now();

            $pengeluaranHarian = DB::table('keu_transaction')
                ->select('value', 'account', 'name', 'description', 'date_transaction')
                ->where('username', $username)
                ->where('status', 'credit')
                ->where('account_category', '6')
                ->whereDate('date_transaction', $targetDate)
                ->where('publish', '1')
                ->orderBy('date_transaction', 'desc')
                ->get();

            $totalPengeluaran = $pengeluaranHarian->sum('value');

            $accounts = DB::table('keu_account')
                ->select('code', 'name')
                ->where('username', $username)
                ->where('code_account_category', '6')
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $kategoriPengeluaran = $pengeluaranHarian->groupBy('account')->map(function ($group, $accountCode) use ($accounts) {
                $accountInfo = $accounts->get($accountCode);
                $totalValue = $group->sum('value');

                return [
                    'account_name' => $accountInfo ? $accountInfo->name : 'Tidak Dikenal',
                    'total' => 'Rp ' . number_format($totalValue, 0, ',', '.')
                ];
            })->values();

            $riwayatTransaksi = $pengeluaranHarian->map(function ($item) {
                return [
                    'deskripsi' => $item->description ?: $item->name,
                    'nilai' => 'Rp ' . number_format($item->value, 0, ',', '.'),
                    'tanggal' => Carbon::parse($item->date_transaction)->format('d/m/Y'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pengeluaran' => 'Rp ' . number_format($totalPengeluaran, 0, ',', '.'),
                    'kategori_pengeluaran' => $kategoriPengeluaran,
                    'riwayat_transaksi' => $riwayatTransaksi
                ],
                'message' => 'Laporan pengeluaran harian berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan pengeluaran harian: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getLaporanPengeluaran7HariTerakhir()
    {
        try {
            $username = auth()->user()->username;
            $endDate = Carbon::now()->endOfDay();
            $startDate = Carbon::now()->subDays(6)->startOfDay();

            $pengeluaran7Hari = DB::table('keu_transaction')
                ->select('value', 'account', 'name', 'description', 'date_transaction')
                ->where('username', $username)
                ->where('status', 'credit')
                ->where('account_category', '6')
                ->whereBetween('date_transaction', [$startDate, $endDate])
                ->where('publish', '1')
                ->orderBy('date_transaction', 'desc')
                ->get();

            $totalPengeluaran = $pengeluaran7Hari->sum('value');

            $accounts = DB::table('keu_account')
                ->select('code', 'name')
                ->where('username', $username)
                ->where('code_account_category', '6')
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $kategoriPengeluaran = $pengeluaran7Hari->groupBy('account')->map(function ($group, $accountCode) use ($accounts) {
                $accountInfo = $accounts->get($accountCode);
                $totalValue = $group->sum('value');

                return [
                    'account_name' => $accountInfo ? $accountInfo->name : 'Tidak Dikenal',
                    'total' => 'Rp ' . number_format($totalValue, 0, ',', '.')
                ];
            })->values();

            $summaryPerHari = collect();
            $currentDate = $startDate->copy();

            $transactionsByDate = $pengeluaran7Hari->groupBy(function ($item) {
                return Carbon::parse($item->date_transaction)->format('Y-m-d');
            });

            while ($currentDate->lte($endDate)) {
                $dateStr = $currentDate->format('Y-m-d');
                $dayTransactions = $transactionsByDate->get($dateStr, collect());
                $totalValue = $dayTransactions->sum('value');

                $summaryPerHari->push([
                    'tanggal' => $currentDate->locale('id')->translatedFormat('d M Y'),
                    'total' => 'Rp ' . number_format($totalValue, 0, ',', '.')
                ]);

                $currentDate->addDay();
            }

            $riwayatTransaksi = $pengeluaran7Hari->map(function ($item) {
                return [
                    'deskripsi' => $item->description ?: $item->name,
                    'nilai' => 'Rp ' . number_format($item->value, 0, ',', '.'),
                    'tanggal' => Carbon::parse($item->date_transaction)->format('d/m/Y'),
                    'waktu' => Carbon::parse($item->date_transaction)->format('H:i')
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pengeluaran' => 'Rp ' . number_format($totalPengeluaran, 0, ',', '.'),
                    'kategori_pengeluaran' => $kategoriPengeluaran,
                    'summary_per_hari' => $summaryPerHari,
                    'riwayat_transaksi' => $riwayatTransaksi
                ],
                'message' => 'Laporan pengeluaran 7 hari terakhir berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan pengeluaran 7 hari terakhir: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getLaporanPengeluaranBulanan(Request $request)
    {
        try {
            $username = auth()->user()->username;
            $validator = Validator::make($request->all(), [
                'month' => 'nullable|integer|min:1|max:12'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Input bulan tidak valid (1-12)',
                    'errors' => $validator->errors()
                ], 422);
            }

            $month = $request->input('month', Carbon::now()->month);
            $year = Carbon::now()->year;
            $startOfMonth = Carbon::create($year, $month, 1)->startOfMonth();
            $endOfMonth = Carbon::create($year, $month, 1)->endOfMonth();

            $pengeluaranBulanan = DB::table('keu_transaction')
                ->select('value', 'account', 'name', 'description', 'date_transaction')
                ->where('username', $username)
                ->where('status', 'credit')
                ->where('account_category', '6')
                ->whereBetween('date_transaction', [$startOfMonth, $endOfMonth])
                ->where('publish', '1')
                ->orderBy('date_transaction', 'desc')
                ->get();

            $totalPengeluaran = $pengeluaranBulanan->sum('value');

            $accounts = DB::table('keu_account')
                ->select('code', 'name')
                ->where('username', $username)
                ->where('code_account_category', '6')
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $kategoriPengeluaran = $pengeluaranBulanan->groupBy('account')->map(function ($group, $accountCode) use ($accounts) {
                $accountInfo = $accounts->get($accountCode);
                $totalValue = $group->sum('value');

                return [
                    'account_name' => $accountInfo ? $accountInfo->name : 'Tidak Dikenal',
                    'total' => 'Rp ' . number_format($totalValue, 0, ',', '.')
                ];
            })->values();

            $summaryPerHari = collect();
            $currentDate = $startOfMonth->copy();

            $transactionsByDate = $pengeluaranBulanan->groupBy(function ($item) {
                return Carbon::parse($item->date_transaction)->format('Y-m-d');
            });

            while ($currentDate->lte($endOfMonth)) {
                $dateStr = $currentDate->format('Y-m-d');
                $dayTransactions = $transactionsByDate->get($dateStr, collect());
                $totalValue = $dayTransactions->sum('value');

                $summaryPerHari->push([
                    'tanggal' => $currentDate->locale('id')->translatedFormat('d M Y'),
                    'total' => 'Rp ' . number_format($totalValue, 0, ',', '.')
                ]);

                $currentDate->addDay();
            }

            $riwayatTransaksi = $pengeluaranBulanan->map(function ($item) {
                return [
                    'deskripsi' => $item->description ?: $item->name,
                    'nilai' => 'Rp ' . number_format($item->value, 0, ',', '.'),
                    'tanggal' => Carbon::parse($item->date_transaction)->format('d/m/Y'),
                    'waktu' => Carbon::parse($item->date_transaction)->format('H:i')
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'periode' => $startOfMonth->locale('id')->translatedFormat('F Y'),
                    'total_pengeluaran' => 'Rp ' . number_format($totalPengeluaran, 0, ',', '.'),
                    'kategori_pengeluaran' => $kategoriPengeluaran,
                    'summary_per_hari' => $summaryPerHari,
                    'riwayat_transaksi' => $riwayatTransaksi
                ],
                'message' => 'Laporan pengeluaran bulanan berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan pengeluaran bulanan: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getLaporanPengeluaranCustom(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Input tanggal tidak valid',
                    'errors' => $validator->errors()
                ], 422);
            }

            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();

            if ($startDate->diffInDays($endDate) > 365) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rentang tanggal maksimal 1 tahun'
                ], 422);
            }

            $pengeluaranCustom = DB::table('keu_transaction')
                ->select('value', 'account', 'name', 'description', 'date_transaction')
                ->where('username', $username)
                ->where('status', 'credit')
                ->where('account_category', '6')
                ->whereBetween('date_transaction', [$startDate, $endDate])
                ->where('publish', '1')
                ->orderBy('date_transaction', 'desc')
                ->get();

            $totalPengeluaran = $pengeluaranCustom->sum('value');

            $accounts = DB::table('keu_account')
                ->select('code', 'name')
                ->where('username', $username)
                ->where('code_account_category', '6')
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $kategoriPengeluaran = $pengeluaranCustom->groupBy('account')->map(function ($group, $accountCode) use ($accounts) {
                $accountInfo = $accounts->get($accountCode);
                $totalValue = $group->sum('value');

                return [
                    'account_name' => $accountInfo ? $accountInfo->name : 'Tidak Dikenal',
                    'total' => 'Rp ' . number_format($totalValue, 0, ',', '.')
                ];
            })->values();

            $summaryPerHari = collect();
            $currentDate = $startDate->copy();

            $transactionsByDate = $pengeluaranCustom->groupBy(function ($item) {
                return Carbon::parse($item->date_transaction)->format('Y-m-d');
            });

            while ($currentDate->lte($endDate)) {
                $dateStr = $currentDate->format('Y-m-d');
                $dayTransactions = $transactionsByDate->get($dateStr, collect());
                $totalValue = $dayTransactions->sum('value');

                $summaryPerHari->push([
                    'tanggal' => $currentDate->locale('id')->translatedFormat('d M Y'),
                    'total' => 'Rp ' . number_format($totalValue, 0, ',', '.')
                ]);

                $currentDate->addDay();
            }

            $riwayatTransaksi = $pengeluaranCustom->map(function ($item) {
                return [
                    'deskripsi' => $item->description ?: $item->name,
                    'nilai' => 'Rp ' . number_format($item->value, 0, ',', '.'),
                    'tanggal' => Carbon::parse($item->date_transaction)->format('d/m/Y'),
                    'waktu' => Carbon::parse($item->date_transaction)->format('H:i')
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'periode' => $startDate->locale('id')->translatedFormat('d F Y') . ' - ' . $endDate->locale('id')->translatedFormat('d F Y'),
                    'jumlah_hari' => $startDate->diffInDays($endDate) + 1,
                    'total_pengeluaran' => 'Rp ' . number_format($totalPengeluaran, 0, ',', '.'),
                    'kategori_pengeluaran' => $kategoriPengeluaran,
                    'summary_per_hari' => $summaryPerHari,
                    'riwayat_transaksi' => $riwayatTransaksi
                ],
                'message' => 'Laporan pengeluaran custom berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan pengeluaran custom: ' . $e->getMessage()
            ], 500);
        }
    }

}