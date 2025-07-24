<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Helpers\GoogleCloudStorageHelper;
use Illuminate\Support\Facades\Validator;

class PiutangController extends Controller
{
    public function getDashboardPiutang(Request $request)
    {
        try {
            $username = auth()->user()->username;
            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->get()
                ->keyBy('code');
            $dataPiutang = DB::table('keu_receivable')
                ->select('code', 'value')
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('publish', '1')
                ->get();

            $totalPiutangBruto = 0;
            $totalPiutangTerbayar = 0;

            foreach ($dataPiutang as $piutang) {
                $totalPiutangBruto += $piutang->value;

                $pembayaran = DB::table('keu_receivable_installment')
                    ->where('user', $username)
                    ->where('code_receivable', $piutang->code)
                    ->where('publish', '1')
                    ->sum('value');

                $totalPiutangTerbayar += $pembayaran;
            }

            $totalPiutang = $totalPiutangBruto - $totalPiutangTerbayar;

            $daftarPiutang = DB::table('keu_receivable')
                ->select(
                    'no as id',
                    'name as nama',
                    'value as jumlah',
                    'date_deadline as tanggal_jatuh_tempo',
                    'date_transaction as tanggal_transaksi',
                    'account as kategori_akun',
                    'description as keterangan',
                    'code'
                )
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('publish', '1')
                ->orderBy('date_deadline', 'asc')
                ->limit(5)
                ->get();

            $processedPiutang = [];
            foreach ($daftarPiutang as $item) {
                $id = $item->id ?? 0;
                $nama = $item->nama ?? 'Piutang';
                $jumlah = $item->jumlah ?? 0;
                $tanggalJatuhTempo = $item->tanggal_jatuh_tempo ?? date('Y-m-d');
                $kategoriAkun = $item->kategori_akun ?? '';
                $keterangan = $item->keterangan ?? '';
                $code = $item->code ?? '';

                $accountName = isset($accounts[$kategoriAkun])
                    ? $accounts[$kategoriAkun]->name
                    : 'Umum';

                $paymentsMade = DB::table('keu_receivable_installment')
                    ->where('user', $username)
                    ->where('code_receivable', $code)
                    ->where('publish', '1')
                    ->sum('value');

                $sisaPiutang = $jumlah - $paymentsMade;
                $status = ($sisaPiutang <= 0) ? 'Lunas' : 'Belum Lunas';

                $tanggal = date('d M Y', strtotime($tanggalJatuhTempo));

                $processedPiutang[] = [
                    'id' => (string) $id,
                    'nama' => (string) $nama,
                    'jumlah' => (int) $jumlah,
                    'formatted_jumlah' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                    'sisa' => (int) max(0, $sisaPiutang),
                    'formatted_sisa' => 'Rp ' . number_format(max(0, $sisaPiutang), 0, ',', '.'),
                    'tanggal' => (string) $tanggal,
                    'tanggal_jatuh_tempo' => (string) $tanggalJatuhTempo,
                    'kategori' => (string) $accountName,
                    'kategori_akun' => (string) $kategoriAkun,
                    'status' => (string) $status,
                    'keterangan' => (string) $keterangan,
                    'code' => (string) $code
                ];
            }

            $daftarCicilan = DB::table('keu_receivable_installment as ri')
                ->leftJoin('keu_receivable as r', 'ri.code_receivable', '=', 'r.code')
                ->select(
                    'ri.no as id',
                    'ri.name as nama',
                    'ri.value as jumlah',
                    'r.date_deadline as tanggal_jatuh_tempo',
                    'ri.date_transaction as tanggal_transaksi',
                    'r.account as kategori_akun',
                    'ri.description as keterangan',
                    'r.code',
                    'r.value as total_receivable'
                )
                ->where('ri.user', $username)
                ->where('r.status', 'debit')
                ->where('ri.publish', '1')
                ->where('r.publish', '1')
                ->orderBy('ri.date_transaction', 'desc')
                ->limit(5)
                ->get();

            $processedCicilan = [];
            foreach ($daftarCicilan as $item) {
                $id = $item->id ?? 0;
                $nama = $item->nama ?? 'Cicilan Piutang';
                $jumlah = $item->jumlah ?? 0;
                $totalPiutang = $item->total_receivable ?? 0;
                $tanggalTransaksi = $item->tanggal_transaksi ? date('Y-m-d', strtotime($item->tanggal_transaksi)) : date('Y-m-d');
                $kategoriAkun = $item->kategori_akun ?? '';
                $keterangan = $item->keterangan ?? '';
                $code = $item->code ?? '';

                $accountName = isset($accounts[$kategoriAkun])
                    ? $accounts[$kategoriAkun]->name
                    : 'Umum';

                $totalDibayar = DB::table('keu_receivable_installment')
                    ->where('user', $username)
                    ->where('code_receivable', $code)
                    ->where('publish', '1')
                    ->sum('value');

                $sisaPiutang = $totalPiutang - $totalDibayar;
                $status = ($sisaPiutang <= 0) ? 'Lunas' : 'Belum Lunas';

                $tanggal = date('d M Y', strtotime($tanggalTransaksi));

                $processedCicilan[] = [
                    'id' => (string) $id,
                    'nama' => (string) $nama,
                    'jumlah' => (int) $jumlah,
                    'formatted_jumlah' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                    'sisa' => (int) max(0, $sisaPiutang),
                    'formatted_sisa' => 'Rp ' . number_format(max(0, $sisaPiutang), 0, ',', '.'),
                    'tanggal' => (string) $tanggal,
                    'tanggal_transaksi' => (string) $tanggalTransaksi,
                    'kategori' => (string) $accountName,
                    'kategori_akun' => (string) $kategoriAkun,
                    'status' => (string) $status,
                    'keterangan' => (string) $keterangan,
                    'code' => (string) $code
                ];
            }
            $piutangByKategori = DB::table('keu_receivable')
                ->select(
                    'account as account',
                    DB::raw('SUM(value) as total_value')
                )
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('publish', '1')
                ->groupBy('account')
                ->get();

            $kategoriPiutang = [];
            $totalNilaiPiutang = 0;

            foreach ($piutangByKategori as $kategori) {
                $accountCode = $kategori->account ?? '';
                $totalValue = $kategori->total_value ?? 0;

                if (isset($accounts[$accountCode])) {
                    $accountInfo = $accounts[$accountCode];
                    $accountName = $accountInfo->name ?? 'Umum';

                    $totalPembayaranKategori = DB::table('keu_receivable as r')
                        ->join('keu_receivable_installment as ri', 'r.code', '=', 'ri.code_receivable')
                        ->where('r.username', $username)
                        ->where('r.account', $accountCode)
                        ->where('r.status', 'debit')
                        ->where('r.publish', '1')
                        ->where('ri.publish', '1')
                        ->sum('ri.value');

                    $nilaiSisa = $totalValue - $totalPembayaranKategori;

                    $kategoriPiutang[] = [
                        'nama_kategori' => (string) $accountName,
                        'kode_kategori' => (string) $accountCode,
                        'status' => 'debit',
                        'total_value' => (int) $totalValue,
                        'total_dibayar' => (int) $totalPembayaranKategori,
                        'sisa' => (int) $nilaiSisa,
                        'formatted_total' => 'Rp ' . number_format($totalValue, 0, ',', '.'),
                        'formatted_sisa' => 'Rp ' . number_format($nilaiSisa, 0, ',', '.')
                    ];

                    $totalNilaiPiutang += $nilaiSisa;
                }
            }
            return response()->json([
                'success' => true,
                'data' => [
                    'ringkasan' => [
                        'totalPiutangBruto' => (int) $totalPiutangBruto,
                        'totalPiutangTerbayar' => (int) $totalPiutangTerbayar,
                        'totalPiutang' => (int) $totalNilaiPiutang,
                        'formatted_totalPiutangBruto' => 'Rp ' . number_format($totalPiutangBruto, 0, ',', '.'),
                        'formatted_totalPiutangTerbayar' => 'Rp ' . number_format($totalPiutangTerbayar, 0, ',', '.'),
                        'formatted_totalPiutang' => 'Rp ' . number_format($totalNilaiPiutang, 0, ',', '.'), // Sesuaikan ini juga
                        'piutangCount' => count($processedPiutang),
                        'cicilanCount' => count($processedCicilan)
                    ],

                    'ringkasan_kategori' => [
                        'kategori_piutang' => $kategoriPiutang,
                        'total_piutang' => (int) $totalNilaiPiutang,
                        'formatted_total_piutang' => 'Rp ' . number_format($totalNilaiPiutang, 0, ',', '.')
                    ],

                    'daftarPiutang' => $processedPiutang,
                    'daftarCicilanPiutang' => $processedCicilan
                ],
                'message' => 'Data piutang berhasil dimuat'
            ], 200);
        } catch (\Exception $e) {

        }
    }

    public function tambahPiutang(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:50',
                'description' => 'required|string|max:200',
                'amount' => 'required|numeric|min:1',
                'transaction_date' => 'required|date',
                'due_date' => 'required|date',
                'source_account' => 'required|string',
                'destination_account' => 'required|string',
                'attachment' => 'nullable|image|mimes:jpeg,png,jpg,pdf,doc,docx|max:5120', // 5MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $username = auth()->user()->username;
            $subdomain = auth()->user()->subdomain ?? '';

            $lastPiutang = DB::table('keu_receivable')
                ->where('username', $username)
                ->where('publish', '1')
                ->orderBy('no', 'desc')
                ->first();

            $lastNumber = 0;
            if ($lastPiutang) {
                $lastCode = $lastPiutang->code;
                if (preg_match('/R(\d+)/', $lastCode, $matches)) {
                    $lastNumber = (int) $matches[1];
                }
            }

            $newNumber = $lastNumber + 1;
            $code = 'R' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

            $piutangData = [
                'username' => $username,
                'subdomain' => $subdomain,
                'code' => $code,
                'user' => $username,
                'status' => 'debit',
                'account_category' => substr($request->source_account, 0, 1),
                'account' => $request->source_account,
                'account_related' => $request->destination_account,
                'name' => $request->name,
                'link' => strtolower(str_replace(' ', '-', $request->name)),
                'description' => $request->description,
                'value' => $request->amount,
                'date_deadline' => date('Y-m-d', strtotime($request->due_date)),
                'date_transaction' => date('Y-m-d', strtotime($request->transaction_date)),
                'date' => now(),
                'publish' => '1'
            ];

            $attachmentPath = '';
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = 'piutang/' . $username . '/' . $code . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if ($uploaded) {
                    $attachmentPath = $filename;
                }
            }

            if ($attachmentPath) {
                $piutangData['attachment'] = $attachmentPath;
            }

            $piutangId = DB::table('keu_receivable')->insertGetId($piutangData);

            DB::table('notifications')->insert([
                'username' => $username,
                'title' => 'Piutang Baru Ditambahkan',
                'message' => sprintf(
                    'Piutang "%s" sebesar %s berhasil dicatat pada %s. Jatuh tempo: %s. Kode piutang: %s',
                    $request->name,
                    'Rp ' . number_format($request->amount, 0, ',', '.'),
                    Carbon::parse($request->transaction_date)->locale('id')->translatedFormat('d F Y'),
                    Carbon::parse($request->due_date)->locale('id')->translatedFormat('d F Y'),
                    $code
                ),
                'is_read' => '0',
                'icon' => 'hand_money_line',
                'priority' => $request->amount >= 5000000 ? 'high' : 'normal',
                'date' => now(),
                'publish' => '1'
            ]);

            $piutang = DB::table('keu_receivable')
                ->where('no', $piutangId)
                ->first();

            $sourceAccountInfo = DB::table('keu_account')
                ->where('code', $request->source_account)
                ->where('username', $username)
                ->first();

            $destinationAccountInfo = DB::table('keu_account')
                ->where('code', $request->destination_account)
                ->where('username', $username)
                ->first();

            $attachmentUrl = null;
            if ($attachmentPath) {
                $attachmentUrl = GoogleCloudStorageHelper::getFileUrl($attachmentPath);
            }

            $response = [
                'success' => true,
                'data' => [
                    'id' => $piutangId,
                    'code' => $code,
                    'name' => $request->name,
                    'description' => $request->description,
                    'amount' => $request->amount,
                    'formatted_amount' => 'Rp ' . number_format($request->amount, 0, ',', '.'),
                    'transaction_date' => date('d M Y', strtotime($request->transaction_date)),
                    'due_date' => date('d M Y', strtotime($request->due_date)),
                    'source_account' => [
                        'code' => $request->source_account,
                        'name' => $sourceAccountInfo ? $sourceAccountInfo->name : 'Unknown'
                    ],
                    'destination_account' => [
                        'code' => $request->destination_account,
                        'name' => $destinationAccountInfo ? $destinationAccountInfo->name : 'Unknown'
                    ],
                    'attachment' => $attachmentUrl,
                    'attachment_path' => $attachmentPath,
                    'status' => 'Belum Lunas'
                ],
                'message' => 'Piutang berhasil ditambahkan'
            ];

            return response()->json($response, 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan piutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getAccountsForPiutang()
    {
        try {
            $username = auth()->user()->username;

            $sourceAccounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('code_account_category', '1')
                ->where('type', 'P')
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
                ->where('code', 'like', '3%')
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

            // $relatedAccounts = DB::table('keu_account')
            //     ->where('username', $username)
            //     ->where('code_account_category', '1')
            //     ->where('related', '1')
            //     ->where('type', 'I')
            //     ->where('publish', '1')
            //     ->orderBy('code', 'asc')
            //     ->get()
            //     ->map(function ($account) {
            //         return [
            //             'code' => $account->code,
            //             'name' => $account->code . ' - ' . $account->name,
            //             'description' => $account->description
            //         ];
            //     });

            return response()->json([
                'success' => true,
                'data' => [
                    'source_accounts' => $sourceAccounts,
                    'related_accounts' => $relatedAccounts
                ],
                'message' => 'Data akun piutang berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data akun piutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getDaftarPiutang(Request $request)
    {
        try {
            $username = auth()->user()->username;
            $status = $request->input('status', 'all');
            $period = $request->input('period', 'all');
            $search = $request->input('search', '');
            $sort = $request->input('sort', 'newest');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $query = DB::table('keu_receivable')
                ->select(
                    'no as id',
                    'name as nama',
                    'value as jumlah',
                    'date_deadline as tanggal_jatuh_tempo',
                    'date_transaction as tanggal_transaksi',
                    'account as kategori_akun',
                    'description as keterangan',
                    'code',
                    'status as tipe_piutang'
                )
                ->where('username', $username)
                ->where('publish', '1')
                ->where('status', 'debit');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            if ($period === 'today') {
                $query->whereDate('date_deadline', date('Y-m-d'));
            } elseif ($period === 'week') {
                $startOfWeek = date('Y-m-d', strtotime('monday this week'));
                $endOfWeek = date('Y-m-d', strtotime('sunday this week'));
                $query->whereBetween('date_deadline', [$startOfWeek, $endOfWeek]);
            } elseif ($period === 'month') {
                $startOfMonth = date('Y-m-01');
                $endOfMonth = date('Y-m-t');
                $query->whereBetween('date_deadline', [$startOfMonth, $endOfMonth]);
            } elseif ($period === 'year') {
                $startOfYear = date('Y-01-01');
                $endOfYear = date('Y-12-31');
                $query->whereBetween('date_deadline', [$startOfYear, $endOfYear]);
            } elseif ($period === 'custom' && $startDate && $endDate) {
                $query->whereBetween('date_deadline', [$startDate, $endDate]);
            }

            $daftarPiutang = $query->get();

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $processedPiutang = [];
            foreach ($daftarPiutang as $item) {
                $id = $item->id ?? 0;
                $nama = $item->nama ?? 'Piutang';
                $jumlah = $item->jumlah ?? 0;
                $tanggalJatuhTempo = $item->tanggal_jatuh_tempo ?? date('Y-m-d');
                $tanggalTransaksi = $item->tanggal_transaksi ?? date('Y-m-d');
                $kategoriAkun = $item->kategori_akun ?? '';
                $keterangan = $item->keterangan ?? '';
                $code = $item->code ?? '';
                $tipePiutang = $item->tipe_piutang ?? 'debit';

                $accountName = isset($accounts[$kategoriAkun])
                    ? $accounts[$kategoriAkun]->name
                    : 'Umum';

                $paymentsMade = DB::table('keu_receivable_installment')
                    ->where('user', $username)
                    ->where('code_receivable', $code)
                    ->where('publish', '1')
                    ->sum('value');

                $sisaPiutang = $jumlah - $paymentsMade;
                $status = ($sisaPiutang <= 0) ? 'Lunas' : 'Belum Lunas';

                if ($status === 'paid' && $status !== 'Lunas')
                    continue;
                if ($status === 'unpaid' && $status !== 'Belum Lunas')
                    continue;

                $tanggalJatuhTempoFormatted = date('d M Y', strtotime($tanggalJatuhTempo));
                $tanggalTransaksiFormatted = date('d M Y', strtotime($tanggalTransaksi));

                $processedPiutang[] = [
                    'id' => (string) $id,
                    'nama' => (string) $nama,
                    'jumlah' => (int) $jumlah,
                    'formatted_jumlah' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                    'tanggal' => (string) $tanggalJatuhTempoFormatted,
                    'tanggal_jatuh_tempo' => (string) $tanggalJatuhTempo,
                    'tanggal_transaksi' => (string) $tanggalTransaksi,
                    'kategori' => (string) $accountName,
                    'kategori_akun' => (string) $kategoriAkun,
                    'keterangan' => (string) $keterangan,
                    'status' => (string) $status,
                    'code' => (string) $code,
                    'tipe_piutang' => (string) $tipePiutang,
                    'total_pembayaran' => (int) $paymentsMade,
                    'formatted_pembayaran' => 'Rp ' . number_format($paymentsMade, 0, ',', '.'),
                    'sisa' => (int) max(0, $sisaPiutang),
                    'formatted_sisa' => 'Rp ' . number_format(max(0, $sisaPiutang), 0, ',', '.')
                ];
            }

            if ($sort === 'newest') {
                usort($processedPiutang, function ($a, $b) {
                    return strtotime($b['tanggal_jatuh_tempo']) - strtotime($a['tanggal_jatuh_tempo']);
                });
            } elseif ($sort === 'oldest') {
                usort($processedPiutang, function ($a, $b) {
                    return strtotime($a['tanggal_jatuh_tempo']) - strtotime($b['tanggal_jatuh_tempo']);
                });
            } elseif ($sort === 'highest') {
                usort($processedPiutang, function ($a, $b) {
                    return $b['jumlah'] - $a['jumlah'];
                });
            } elseif ($sort === 'lowest') {
                usort($processedPiutang, function ($a, $b) {
                    return $a['jumlah'] - $b['jumlah'];
                });
            } elseif ($sort === 'nama_asc') {
                usort($processedPiutang, function ($a, $b) {
                    return strcmp($a['nama'], $b['nama']);
                });
            } elseif ($sort === 'nama_desc') {
                usort($processedPiutang, function ($a, $b) {
                    return strcmp($b['nama'], $a['nama']);
                });
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'daftar_piutang' => $processedPiutang,
                    'total_count' => count($processedPiutang)
                ],
                'message' => 'Data daftar piutang berhasil dimuat'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data daftar piutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getDetailPiutang(Request $request, $id)
    {
        try {
            $username = auth()->user()->username;

            $isPiutang = DB::table('keu_receivable')
                ->where('username', $username)
                ->where('no', $id)
                ->exists();

            $isInstallment = DB::table('keu_receivable_installment')
                ->where('user', $username)
                ->where('no', $id)
                ->exists();

            if (!$isPiutang && !$isInstallment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data piutang tidak ditemukan'
                ], 404);
            }

            $receivableCode = '';

            if ($isPiutang) {
                $piutang = DB::table('keu_receivable')
                    ->select(
                        'no as id',
                        'name as nama',
                        'value as jumlah',
                        'date_deadline as tanggal_jatuh_tempo',
                        'date_transaction as tanggal_transaksi',
                        'account as kategori_akun',
                        'description as keterangan',
                        'code',
                        'status'
                    )
                    ->where('username', $username)
                    ->where('no', $id)
                    ->first();

                $receivableCode = $piutang->code;
            } else {
                $installment = DB::table('keu_receivable_installment')
                    ->where('user', $username)
                    ->where('no', $id)
                    ->first();

                $receivableCode = $installment->code_receivable;

                $piutang = DB::table('keu_receivable')
                    ->select(
                        'no as id',
                        'name as nama',
                        'value as jumlah',
                        'date_deadline as tanggal_jatuh_tempo',
                        'date_transaction as tanggal_transaksi',
                        'account as kategori_akun',
                        'description as keterangan',
                        'code',
                        'status'
                    )
                    ->where('username', $username)
                    ->where('code', $receivableCode)
                    ->first();
            }

            if (!$piutang) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data piutang tidak ditemukan'
                ], 404);
            }

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $kategoriAkun = $piutang->kategori_akun ?? '';
            $accountName = isset($accounts[$kategoriAkun])
                ? $accounts[$kategoriAkun]->name
                : 'Umum';

            // Ambil data cicilan
            $cicilan = DB::table('keu_receivable_installment')
                ->select(
                    'no as id',
                    'name as nama',
                    'value as jumlah',
                    'date_transaction as tanggal_transaksi',
                    'description as keterangan'
                )
                ->where('user', $username)
                ->where('code_receivable', $receivableCode)
                ->where('publish', '1')
                ->orderBy('date_transaction', 'desc')
                ->get();

            $processedCicilan = [];
            $totalCicilan = 0;

            foreach ($cicilan as $item) {
                $id = $item->id ?? 0;
                $nama = $item->nama ?? 'Cicilan';
                $jumlah = $item->jumlah ?? 0;
                $tanggalTransaksi = $item->tanggal_transaksi ?? date('Y-m-d');
                $keterangan = $item->keterangan ?? '';

                $tanggal = date('d M Y', strtotime($tanggalTransaksi));

                $processedCicilan[] = [
                    'id' => (string) $id,
                    'nama' => (string) $nama,
                    'jumlah' => (int) $jumlah,
                    'formatted_jumlah' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                    'tanggal' => (string) $tanggal,
                    'tanggal_raw' => (string) $tanggalTransaksi,
                    'keterangan' => (string) $keterangan
                ];

                $totalCicilan += $jumlah;
            }

            // Hitung sisa piutang
            $totalPiutang = $piutang->jumlah ?? 0;
            $sisaPiutang = $totalPiutang - $totalCicilan;
            $status = $sisaPiutang <= 0 ? 'Lunas' : 'Belum Lunas';

            $tanggalJatuhTempo = $piutang->tanggal_jatuh_tempo ?? date('Y-m-d');
            $tanggalTransaksi = $piutang->tanggal_transaksi ?? date('Y-m-d');

            $formattedTanggalJatuhTempo = date('d M Y', strtotime($tanggalJatuhTempo));
            $formattedTanggalTransaksi = date('d M Y', strtotime($tanggalTransaksi));

            $detailPiutang = [
                'id' => (string) $piutang->id,
                'nama' => (string) $piutang->nama,
                'jumlah' => (int) $totalPiutang,
                'formatted_jumlah' => 'Rp ' . number_format($totalPiutang, 0, ',', '.'),
                'tanggal_jatuh_tempo' => (string) $formattedTanggalJatuhTempo,
                'tanggal_jatuh_tempo_raw' => (string) $tanggalJatuhTempo,
                'tanggal_transaksi' => (string) $formattedTanggalTransaksi,
                'tanggal_transaksi_raw' => (string) $tanggalTransaksi,
                'kategori' => (string) $accountName,
                'kategori_akun' => (string) $kategoriAkun,
                'keterangan' => (string) $piutang->keterangan,
                'status' => (string) $status,
                'code' => (string) $piutang->code,
                'tipe_piutang' => (string) $piutang->status,
                'total_cicilan' => (int) $totalCicilan,
                'formatted_total_cicilan' => 'Rp ' . number_format($totalCicilan, 0, ',', '.'),
                'sisa_piutang' => (int) max(0, $sisaPiutang),
                'formatted_sisa_piutang' => 'Rp ' . number_format(max(0, $sisaPiutang), 0, ',', '.')
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'detail_piutang' => $detailPiutang,
                    'daftar_cicilan' => $processedCicilan
                ],
                'message' => 'Data detail piutang berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data detail piutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function tambahCicilanPiutang(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'piutang_id' => 'required',
                'piutang_code' => 'required|string',
                'amount' => 'required|numeric|min:1',
                'description' => 'required|string|max:200',
                'transaction_date' => 'required|date',
                'source_account' => 'required|string',
                'name' => 'required|string|max:50',
                'attachment' => 'nullable|image|mimes:jpeg,png,jpg,pdf,doc,docx|max:5120', // 5MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $username = auth()->user()->username;
            $subdomain = auth()->user()->subdomain ?? '';

            $piutang = DB::table('keu_receivable')
                ->where('username', $username)
                ->where('code', $request->piutang_code)
                ->where('publish', '1')
                ->first();

            if (!$piutang) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data piutang tidak ditemukan'
                ], 404);
            }

            $totalCicilan = DB::table('keu_receivable_installment')
                ->where('user', $username)
                ->where('code_receivable', $request->piutang_code)
                ->where('publish', '1')
                ->sum('value');

            $totalPiutang = $piutang->value;
            $sisaPiutang = $totalPiutang - $totalCicilan;

            if ($request->amount > $sisaPiutang) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jumlah cicilan melebihi sisa piutang',
                    'data' => [
                        'sisa_piutang' => $sisaPiutang,
                        'jumlah_cicilan' => $request->amount
                    ]
                ], 422);
            }

            $lastCicilan = DB::table('keu_receivable_installment')
                ->where('user', $username)
                ->where('publish', '1')
                ->orderBy('no', 'desc')
                ->first();

            $lastNumber = 0;
            if ($lastCicilan) {
                $lastCode = $lastCicilan->code;
                if (preg_match('/PR(\d+)/', $lastCode, $matches)) {
                    $lastNumber = (int) $matches[1];
                }
            }

            $newNumber = $lastNumber + 1;
            $code = 'PR' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

            // Upload attachment ke Google Cloud Storage
            $attachmentPath = '';
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = 'piutang/bukti_cicilan/' . $username . '/' . $code . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if ($uploaded) {
                    $attachmentPath = $filename;
                }
            }

            $cicilanData = [
                'user' => $username,
                'code' => $code,
                'code_receivable' => $request->piutang_code,
                'account_category' => substr($request->source_account, 0, 1),
                'account' => $request->source_account,
                'account_related' => $piutang->account_related,
                'name' => $request->name,
                'link' => 'penerimaan-piutang-' . $code,
                'description' => $request->description,
                'value' => $request->amount,
                'date_transaction' => date('Y-m-d', strtotime($request->transaction_date)),
                'date' => now(),
                'publish' => '1'
            ];

            if ($attachmentPath) {
                $cicilanData['attachment'] = $attachmentPath;
            }

            $cicilanId = DB::table('keu_receivable_installment')->insertGetId($cicilanData);

            $sisaPiutangSetelahCicilan = $sisaPiutang - $request->amount;
            $statusPiutang = $sisaPiutangSetelahCicilan <= 0 ? 'LUNAS' : 'BELUM LUNAS';

            // Insert notifikasi
            DB::table('notifications')->insert([
                'username' => $username,
                'title' => 'Cicilan Piutang Diterima',
                'message' => sprintf(
                    'Cicilan piutang "%s" sebesar %s berhasil dicatat pada %s. Sisa piutang: %s. Status: %s',
                    $piutang->name,
                    'Rp ' . number_format($request->amount, 0, ',', '.'),
                    Carbon::parse($request->transaction_date)->locale('id')->translatedFormat('d F Y'),
                    'Rp ' . number_format($sisaPiutangSetelahCicilan, 0, ',', '.'),
                    $statusPiutang
                ),
                'is_read' => '0',
                'icon' => 'hand_money_line',
                'priority' => $sisaPiutangSetelahCicilan <= 0 ? 'high' : 'normal',
                'date' => now(),
                'publish' => '1'
            ]);

            $newTotalCicilan = $totalCicilan + $request->amount;

            $sourceAccountInfo = DB::table('keu_account')
                ->where('code', $request->source_account)
                ->where('username', $username)
                ->first();

            // Generate attachment URL
            $attachmentUrl = null;
            if ($attachmentPath) {
                $attachmentUrl = GoogleCloudStorageHelper::getFileUrl($attachmentPath);
            }

            $response = [
                'success' => true,
                'data' => [
                    'id' => $cicilanId,
                    'code' => $code,
                    'piutang_code' => $request->piutang_code,
                    'name' => $request->name,
                    'description' => $request->description,
                    'amount' => $request->amount,
                    'formatted_amount' => 'Rp ' . number_format($request->amount, 0, ',', '.'),
                    'transaction_date' => date('d M Y', strtotime($request->transaction_date)),
                    'source_account' => [
                        'code' => $request->source_account,
                        'name' => $sourceAccountInfo ? $sourceAccountInfo->name : 'Unknown'
                    ],
                    'total_piutang' => $totalPiutang,
                    'formatted_total_piutang' => 'Rp ' . number_format($totalPiutang, 0, ',', '.'),
                    'total_cicilan' => $newTotalCicilan,
                    'formatted_total_cicilan' => 'Rp ' . number_format($newTotalCicilan, 0, ',', '.'),
                    'sisa_piutang' => $sisaPiutangSetelahCicilan,
                    'formatted_sisa_piutang' => 'Rp ' . number_format($sisaPiutangSetelahCicilan, 0, ',', '.'),
                    'status' => $sisaPiutangSetelahCicilan <= 0 ? 'Lunas' : 'Belum Lunas',
                    'attachment' => $attachmentUrl,
                    'attachment_path' => $attachmentPath
                ],
                'message' => 'Cicilan piutang berhasil ditambahkan'
            ];

            return response()->json($response, 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan cicilan piutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function getAccountsForTambahCicilanPiutang()
    {
        try {
            $username = auth()->user()->username;

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('code_account_category', '1')
                ->where('related', '1')
                ->where('type', 'I')
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
                    'accounts' => $accounts
                ],
                'message' => 'Data akun untuk tambah cicilan piutang berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data akun untuk tambah cicilan piutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getLaporanPiutang()
    {
        try {
            $username = auth()->user()->username;

            $daftarPiutang = DB::table('keu_receivable')
                ->select('name', 'description', 'date_transaction', 'date_deadline', 'value', 'code')
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('publish', '1')
                ->orderBy('date_deadline', 'asc')
                ->get();

            $totalPiutang = 0;
            $laporanPiutang = [];

            foreach ($daftarPiutang as $piutang) {
                $totalCicilan = DB::table('keu_receivable_installment')
                    ->where('user', $username)
                    ->where('code_receivable', $piutang->code)
                    ->where('publish', '1')
                    ->sum('value');

                $sisaPiutang = $piutang->value - $totalCicilan;
                $totalPiutang += $sisaPiutang;

                $laporanPiutang[] = [
                    'nama_debitur' => $piutang->name,
                    'deskripsi' => $piutang->description ?: '',
                    'tanggal_piutang' => Carbon::parse($piutang->date_transaction)->locale('id')->translatedFormat('d F Y'),
                    'tanggal_jatuh_tempo' => Carbon::parse($piutang->date_deadline)->locale('id')->translatedFormat('d F Y'),
                    'total_piutang' => number_format($piutang->value, 0, ',', '.'),
                    'total_cicilan' => number_format($totalCicilan, 0, ',', '.'),
                    'sisa_piutang' => number_format(max(0, $sisaPiutang), 0, ',', '.')
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_piutang' => number_format($totalPiutang, 0, ',', '.'),
                    'daftar_piutang' => $laporanPiutang
                ],
                'message' => 'Laporan piutang berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan piutang: ' . $e->getMessage()
            ], 500);
        }
    }
}