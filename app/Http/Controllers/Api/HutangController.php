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

class HutangController extends Controller
{

    public function getDashboardHutang(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $dataHutang = DB::table('keu_debt')
                ->select('code', 'value')
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('publish', '1')
                ->get();

            $dataPiutang = DB::table('keu_debt')
                ->select('code', 'value')
                ->where('username', $username)
                ->where('status', 'credit')
                ->where('publish', '1')
                ->get();

            $totalHutangBruto = 0;
            $totalHutangTerbayar = 0;

            foreach ($dataHutang as $hutang) {
                $totalHutangBruto += $hutang->value;

                $pembayaran = DB::table('keu_debt_installment')
                    ->where('username', $username)
                    ->where('code_debt', $hutang->code)
                    ->where('publish', '1')
                    ->sum('value');

                $totalHutangTerbayar += $pembayaran;
            }

            $totalHutang = $totalHutangBruto - $totalHutangTerbayar;

            $totalPiutangBruto = 0;
            $totalPiutangTerbayar = 0;

            foreach ($dataPiutang as $piutang) {
                $totalPiutangBruto += $piutang->value;

                $pembayaran = DB::table('keu_debt_installment')
                    ->where('username', $username)
                    ->where('code_debt', $piutang->code)
                    ->where('publish', '1')
                    ->sum('value');

                $totalPiutangTerbayar += $pembayaran;
            }

            $totalTagihan = $totalPiutangBruto - $totalPiutangTerbayar;

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $daftarHutang = DB::table('keu_debt')
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

            $processedHutang = [];
            foreach ($daftarHutang as $item) {
                $id = $item->id ?? 0;
                $nama = $item->nama ?? 'Hutang';
                $jumlah = $item->jumlah ?? 0;
                $tanggalJatuhTempo = $item->tanggal_jatuh_tempo ?? date('Y-m-d');
                $kategoriAkun = $item->kategori_akun ?? '';
                $keterangan = $item->keterangan ?? '';
                $code = $item->code ?? '';

                $accountName = isset($accounts[$kategoriAkun])
                    ? $accounts[$kategoriAkun]->name
                    : 'Umum';

                $paymentsMade = DB::table('keu_debt_installment')
                    ->where('username', $username)
                    ->where('code_debt', $code)
                    ->where('publish', '1')
                    ->sum('value');

                $sisaHutang = $jumlah - $paymentsMade;
                $status = ($sisaHutang <= 0) ? 'Lunas' : 'Belum Lunas';

                $tanggal = date('d M Y', strtotime($tanggalJatuhTempo));

                $processedHutang[] = [
                    'id' => (string) $id,
                    'nama' => (string) $nama,
                    'jumlah' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                    'sisa' => 'Rp ' . number_format(max(0, $sisaHutang), 0, ',', '.'),
                    'tanggal' => (string) $tanggal,
                    'kategori' => (string) $accountName,
                    'status' => (string) $status,
                    'keterangan' => (string) $keterangan
                ];
            }

            $daftarTagihan = DB::table('keu_debt_installment as di')
                ->leftJoin('keu_debt as d', 'di.code_debt', '=', 'd.code')
                ->select(
                    'di.no as id',
                    'di.name as nama',
                    'di.value as jumlah',
                    'd.date_deadline as tanggal_jatuh_tempo',
                    'di.date_transaction as tanggal_transaksi',
                    'd.account as kategori_akun',
                    'd.description as keterangan',
                    'd.code',
                    'd.value as total_debt',
                    'd.status as debt_status'
                )
                ->where('di.username', $username)
                ->where('d.status', 'credit')
                ->where('di.publish', '1')
                ->where('d.publish', '1')
                ->orderBy('di.date_transaction', 'asc')
                ->limit(5)
                ->get();

            $processedTagihan = [];
            foreach ($daftarTagihan as $item) {
                $id = $item->id ?? 0;
                $nama = $item->nama ?? 'Tagihan';
                $jumlah = $item->jumlah ?? 0;
                $totalPiutang = $item->total_debt ?? 0;
                $tanggalJatuhTempo = $item->tanggal_transaksi ?? date('Y-m-d');
                $kategoriAkun = $item->kategori_akun ?? '';
                $keterangan = $item->keterangan ?? '';
                $code = $item->code ?? '';

                $accountName = isset($accounts[$kategoriAkun])
                    ? $accounts[$kategoriAkun]->name
                    : 'Umum';

                $totalDibayar = DB::table('keu_debt_installment')
                    ->where('username', $username)
                    ->where('code_debt', $code)
                    ->where('publish', '1')
                    ->sum('value');

                $sisaPiutang = $totalPiutang - $totalDibayar;
                $status = ($sisaPiutang <= 0) ? 'Lunas' : 'Belum Lunas';

                $tanggal = date('d M Y', strtotime($tanggalJatuhTempo));

                $processedTagihan[] = [
                    'id' => (string) $id,
                    'nama' => (string) $nama,
                    'jumlah' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                    'sisa' => 'Rp ' . number_format(max(0, $sisaPiutang), 0, ',', '.'),
                    'tanggal' => (string) $tanggal,
                    'kategori' => (string) $accountName,
                    'status' => (string) $status,
                    'keterangan' => (string) $keterangan
                ];
            }

            $hutangByKategori = DB::table('keu_debt')
                ->select(
                    'account as account',
                    DB::raw('SUM(value) as total_value')
                )
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('publish', '1')
                ->groupBy('account')
                ->get();

            $kategoriHutang = [];
            $totalNilaiHutang = 0;

            foreach ($hutangByKategori as $kategori) {
                $accountCode = $kategori->account ?? '';
                $totalValue = $kategori->total_value ?? 0;

                if (isset($accounts[$accountCode])) {
                    $accountInfo = $accounts[$accountCode];
                    $accountName = $accountInfo->name ?? 'Umum';


                    $totalPembayaranKategori = DB::table('keu_debt as d')
                        ->join('keu_debt_installment as di', 'd.code', '=', 'di.code_debt')
                        ->where('d.username', $username)
                        ->where('d.account', $accountCode)
                        ->where('d.status', 'debit')
                        ->where('d.publish', '1')
                        ->where('di.publish', '1')
                        ->sum('di.value');

                    $nilaiSisa = $totalValue - $totalPembayaranKategori;

                    $kategoriHutang[] = [
                        'nama_kategori' => (string) $accountName,
                        'kode_kategori' => (string) $accountCode,
                        'status' => 'debit',
                        'total_value' => (int) $totalValue,
                        'total_dibayar' => (int) $totalPembayaranKategori,
                        'sisa' => (int) $nilaiSisa,
                        'formatted_total' => 'Rp ' . number_format($totalValue, 0, ',', '.'),
                        'formatted_sisa' => 'Rp ' . number_format($nilaiSisa, 0, ',', '.')
                    ];

                    $totalNilaiHutang += $nilaiSisa;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'ringkasan' => [
                        'totalHutangBruto' => (int) $totalHutangBruto,
                        'totalHutangTerbayar' => (int) $totalHutangTerbayar,
                        'totalHutang' => (int) $totalHutang,
                        'formatted_totalHutangBruto' => 'Rp ' . number_format($totalHutangBruto, 0, ',', '.'),
                        'formatted_totalHutangTerbayar' => 'Rp ' . number_format($totalHutangTerbayar, 0, ',', '.'),
                        'formatted_totalHutang' => 'Rp ' . number_format($totalHutang, 0, ',', '.'),
                        'totalPiutangBruto' => (int) $totalPiutangBruto,
                        'totalPiutangTerbayar' => (int) $totalPiutangTerbayar,
                        'totalTagihan' => (int) $totalTagihan,
                        'formatted_totalPiutangBruto' => 'Rp ' . number_format($totalPiutangBruto, 0, ',', '.'),
                        'formatted_totalPiutangTerbayar' => 'Rp ' . number_format($totalPiutangTerbayar, 0, ',', '.'),
                        'formatted_totalTagihan' => 'Rp ' . number_format($totalTagihan, 0, ',', '.'),
                        'rekananCount' => count($processedHutang),
                        'penghutangCount' => count($processedTagihan)
                    ],

                    'ringkasan_kategori' => [
                        'kategori_hutang' => $kategoriHutang,
                        'total_hutang' => (int) $totalNilaiHutang,
                        'formatted_total_hutang' => 'Rp ' . number_format($totalNilaiHutang, 0, ',', '.')
                    ],

                    'daftarHutang' => $processedHutang,
                    'daftarTagihan' => $processedTagihan
                ],
                'message' => 'Data hutang berhasil dimuat'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data hutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getDetailHutang(Request $request, $id)
    {
        try {
            $username = auth()->user()->username;

            $isHutang = DB::table('keu_debt')
                ->where('username', $username)
                ->where('no', $id)
                ->exists();

            $isInstallment = DB::table('keu_debt_installment')
                ->where('username', $username)
                ->where('no', $id)
                ->exists();

            if (!$isHutang && !$isInstallment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data hutang tidak ditemukan'
                ], 404);
            }

            $debtCode = '';

            if ($isHutang) {
                $hutang = DB::table('keu_debt')
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

                $debtCode = $hutang->code;
            } else {

                $installment = DB::table('keu_debt_installment')
                    ->where('username', $username)
                    ->where('no', $id)
                    ->first();

                $debtCode = $installment->code_debt;

                $hutang = DB::table('keu_debt')
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
                    ->where('code', $debtCode)
                    ->first();
            }

            if (!$hutang) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data hutang tidak ditemukan'
                ], 404);
            }

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $kategoriAkun = $hutang->kategori_akun ?? '';
            $accountName = isset($accounts[$kategoriAkun])
                ? $accounts[$kategoriAkun]->name
                : 'Umum';

            $cicilan = DB::table('keu_debt_installment')
                ->select(
                    'no as id',
                    'name as nama',
                    'value as jumlah',
                    'date_transaction as tanggal_transaksi',
                    'description as keterangan'
                )
                ->where('username', $username)
                ->where('code_debt', $debtCode)
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

            $totalHutang = $hutang->jumlah ?? 0;
            $sisaHutang = $totalHutang - $totalCicilan;
            $status = $sisaHutang <= 0 ? 'Lunas' : 'Belum Lunas';

            $tanggalJatuhTempo = $hutang->tanggal_jatuh_tempo ?? date('Y-m-d');
            $tanggalTransaksi = $hutang->tanggal_transaksi ?? date('Y-m-d');

            $formattedTanggalJatuhTempo = date('d M Y', strtotime($tanggalJatuhTempo));
            $formattedTanggalTransaksi = date('d M Y', strtotime($tanggalTransaksi));

            $detailHutang = [
                'id' => (string) $hutang->id,
                'nama' => (string) $hutang->nama,
                'jumlah' => (int) $totalHutang,
                'formatted_jumlah' => 'Rp ' . number_format($totalHutang, 0, ',', '.'),
                'tanggal_jatuh_tempo' => (string) $formattedTanggalJatuhTempo,
                'tanggal_jatuh_tempo_raw' => (string) $tanggalJatuhTempo,
                'tanggal_transaksi' => (string) $formattedTanggalTransaksi,
                'tanggal_transaksi_raw' => (string) $tanggalTransaksi,
                'kategori' => (string) $accountName,
                'kategori_akun' => (string) $kategoriAkun,
                'keterangan' => (string) $hutang->keterangan,
                'status' => (string) $status,
                'code' => (string) $hutang->code,
                'tipe_hutang' => (string) $hutang->status,
                'total_cicilan' => (int) $totalCicilan,
                'formatted_total_cicilan' => 'Rp ' . number_format($totalCicilan, 0, ',', '.'),
                'sisa_hutang' => (int) max(0, $sisaHutang),
                'formatted_sisa_hutang' => 'Rp ' . number_format(max(0, $sisaHutang), 0, ',', '.')
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'detail_hutang' => $detailHutang,
                    'daftar_cicilan' => $processedCicilan
                ],
                'message' => 'Data detail hutang berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data detail hutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function getDaftarHutang(Request $request)
    {
        try {
            $username = auth()->user()->username;
            $status = $request->input('status', 'all');
            $period = $request->input('period', 'all');
            $search = $request->input('search', '');
            $sort = $request->input('sort', 'newest');
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $query = DB::table('keu_debt')
                ->select(
                    'no as id',
                    'name as nama',
                    'value as jumlah',
                    'date_deadline as tanggal_jatuh_tempo',
                    'date_transaction as tanggal_transaksi',
                    'account as kategori_akun',
                    'description as keterangan',
                    'code',
                    'status as tipe_hutang'
                )
                ->where('username', $username)
                ->where('publish', '1');

            if ($request->has('tipe') && $request->input('tipe') == 'piutang') {
                $query->where('status', 'credit');
            } else {
                $query->where('status', 'debit');
            }

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

            $daftarHutang = $query->get();

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->get()
                ->keyBy('code');

            $processedHutang = [];
            foreach ($daftarHutang as $item) {
                $id = $item->id ?? 0;
                $nama = $item->nama ?? 'Hutang';
                $jumlah = $item->jumlah ?? 0;
                $tanggalJatuhTempo = $item->tanggal_jatuh_tempo ?? date('Y-m-d');
                $tanggalTransaksi = $item->tanggal_transaksi ?? date('Y-m-d');
                $kategoriAkun = $item->kategori_akun ?? '';
                $keterangan = $item->keterangan ?? '';
                $code = $item->code ?? '';
                $tipeHutang = $item->tipe_hutang ?? 'debit';

                $accountName = isset($accounts[$kategoriAkun])
                    ? $accounts[$kategoriAkun]->name
                    : 'Umum';

                $paymentsMade = DB::table('keu_debt_installment')
                    ->where('username', $username)
                    ->where('code_debt', $code)
                    ->where('publish', '1')
                    ->sum('value');

                $sisaHutang = $jumlah - $paymentsMade;
                $status = ($sisaHutang <= 0) ? 'Lunas' : 'Belum Lunas';

                if ($status === 'paid' && $status !== 'Lunas')
                    continue;
                if ($status === 'unpaid' && $status !== 'Belum Lunas')
                    continue;

                $tanggalJatuhTempoFormatted = date('d M Y', strtotime($tanggalJatuhTempo));
                $tanggalTransaksiFormatted = date('d M Y', strtotime($tanggalTransaksi));

                $processedHutang[] = [
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
                    'tipe_hutang' => (string) $tipeHutang,
                    'total_pembayaran' => (int) $paymentsMade,
                    'formatted_pembayaran' => 'Rp ' . number_format($paymentsMade, 0, ',', '.'),
                    'sisa' => (int) max(0, $sisaHutang),
                    'formatted_sisa' => 'Rp ' . number_format(max(0, $sisaHutang), 0, ',', '.')
                ];
            }

            if ($sort === 'newest') {
                usort($processedHutang, function ($a, $b) {
                    return strtotime($b['tanggal_jatuh_tempo']) - strtotime($a['tanggal_jatuh_tempo']);
                });
            } elseif ($sort === 'oldest') {
                usort($processedHutang, function ($a, $b) {
                    return strtotime($a['tanggal_jatuh_tempo']) - strtotime($b['tanggal_jatuh_tempo']);
                });
            } elseif ($sort === 'highest') {
                usort($processedHutang, function ($a, $b) {
                    return $b['jumlah'] - $a['jumlah'];
                });
            } elseif ($sort === 'lowest') {
                usort($processedHutang, function ($a, $b) {
                    return $a['jumlah'] - $b['jumlah'];
                });
            } elseif ($sort === 'nama_asc') {
                usort($processedHutang, function ($a, $b) {
                    return strcmp($a['nama'], $b['nama']);
                });
            } elseif ($sort === 'nama_desc') {
                usort($processedHutang, function ($a, $b) {
                    return strcmp($b['nama'], $a['nama']);
                });
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'daftar_hutang' => $processedHutang,
                    'total_count' => count($processedHutang)
                ],
                'message' => 'Data daftar hutang berhasil dimuat'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data daftar hutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function tambahHutang(Request $request)
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
                'attachment' => 'nullable|image|mimes:jpeg,png,jpg,pdf,doc,docx|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $username = auth()->user()->username;
            $userName = auth()->user()->name ?? 'system';
            $subdomain = auth()->user()->subdomain ?? '';

            $sourceAccount = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', $request->source_account)
                ->where('code', 'like', '3%')
                ->first();

            if (!$sourceAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun hutang tidak valid (harus akun kewajiban 3xx)'
                ], 404);
            }
            $destinationAccount = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', $request->destination_account)
                ->where('code', 'like', '1%')
                ->where('related', '1')
                ->first();

            if (!$destinationAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun kas/bank tidak valid'
                ], 404);
            }

            $lastHutang = DB::table('keu_debt')
                ->where('username', $username)
                ->where('publish', '1')
                ->orderBy('no', 'desc')
                ->first();

            $lastNumber = 0;
            if ($lastHutang) {
                $lastCode = $lastHutang->code;
                if (preg_match('/D(\d+)/', $lastCode, $matches)) {
                    $lastNumber = (int) $matches[1];
                }
            }

            $newNumber = $lastNumber + 1;
            $code = 'D' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

            $attachmentPath = '';
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = 'hutang/' . $username . '/' . $code . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if ($uploaded) {
                    $attachmentPath = $filename;
                }
            }

            DB::beginTransaction();

            try {
                // Insert ke keu_debt
                $hutangData = [
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $code,
                    'user' => $userName,
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

                if ($attachmentPath) {
                    $hutangData['attachment'] = $attachmentPath;
                }

                $hutangId = DB::table('keu_debt')->insertGetId($hutangData);

                // Buat jurnal otomatis
                // Jurnal: Kas/Bank (Debit) - Hutang (Credit)
                // Debit: Kas/Bank bertambah
                DB::table('keu_journal')->insert([
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $code,
                    'user' => $userName,
                    'status' => 'debit',
                    'account' => $request->destination_account,
                    'name' => $destinationAccount->name,
                    'description' => $request->description,
                    'value' => $request->amount,
                    'date_transaction' => date('Y-m-d', strtotime($request->transaction_date)),
                    'date' => now(),
                    'publish' => '1'
                ]);

                // Credit: Hutang bertambah
                DB::table('keu_journal')->insert([
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $code,
                    'user' => $userName,
                    'status' => 'credit',
                    'account' => $request->source_account,
                    'name' => $sourceAccount->name,
                    'description' => $request->description,
                    'value' => $request->amount,
                    'date_transaction' => date('Y-m-d', strtotime($request->transaction_date)),
                    'date' => now(),
                    'publish' => '1'
                ]);

                DB::commit();

                // Notifikasi
                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Hutang Baru Ditambahkan',
                    'message' => sprintf(
                        'Hutang "%s" sebesar %s berhasil dicatat pada %s. Jatuh tempo: %s. Kode hutang: %s',
                        $request->name,
                        'Rp ' . number_format($request->amount, 0, ',', '.'),
                        Carbon::parse($request->transaction_date)->locale('id')->translatedFormat('d F Y'),
                        Carbon::parse($request->due_date)->locale('id')->translatedFormat('d F Y'),
                        $code
                    ),
                    'is_read' => '0',
                    'icon' => 'hand_coin_line',
                    'priority' => $request->amount >= 5000000 ? 'high' : 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                $attachmentUrl = null;
                if ($attachmentPath) {
                    $attachmentUrl = GoogleCloudStorageHelper::getFileUrl($attachmentPath);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $hutangId,
                        'code' => $code,
                        'name' => $request->name,
                        'description' => $request->description,
                        'amount' => $request->amount,
                        'formatted_amount' => 'Rp ' . number_format($request->amount, 0, ',', '.'),
                        'transaction_date' => date('d M Y', strtotime($request->transaction_date)),
                        'due_date' => date('d M Y', strtotime($request->due_date)),
                        'source_account' => [
                            'code' => $request->source_account,
                            'name' => $sourceAccount->name
                        ],
                        'destination_account' => [
                            'code' => $request->destination_account,
                            'name' => $destinationAccount->name
                        ],
                        'attachment' => $attachmentUrl,
                        'status' => 'Belum Lunas'
                    ],
                    'message' => 'Hutang berhasil ditambahkan dengan jurnal otomatis'
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan hutang: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function tambahCicilan(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'hutang_id' => 'required',
                'hutang_code' => 'required|string',
                'amount' => 'required|numeric|min:1',
                'description' => 'required|string|max:200',
                'transaction_date' => 'required|date',
                'source_account' => 'required|string', // Kas/Bank (1xx)
                'name' => 'required|string|max:50',
                'attachment' => 'nullable|image|mimes:jpeg,png,jpg,pdf,doc,docx|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $username = auth()->user()->username;
            $userName = auth()->user()->name ?? 'system';
            $subdomain = auth()->user()->subdomain ?? '';

            $hutang = DB::table('keu_debt')
                ->where('username', $username)
                ->where('code', $request->hutang_code)
                ->where('publish', '1')
                ->first();

            if (!$hutang) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data hutang tidak ditemukan'
                ], 404);
            }

            // Validasi akun kas/bank
            $sourceAccount = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', $request->source_account)
                ->where('code', 'like', '1%')
                ->where('related', '1')
                ->first();

            if (!$sourceAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun kas/bank tidak valid'
                ], 404);
            }

            // Validasi sisa hutang
            $totalCicilan = DB::table('keu_debt_installment')
                ->where('username', $username)
                ->where('code_debt', $request->hutang_code)
                ->where('publish', '1')
                ->sum('value');

            $sisaHutang = $hutang->value - $totalCicilan;

            if ($request->amount > $sisaHutang) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jumlah cicilan melebihi sisa hutang',
                    'data' => [
                        'sisa_hutang' => $sisaHutang,
                        'jumlah_cicilan' => $request->amount
                    ]
                ], 422);
            }

            $lastCicilan = DB::table('keu_debt_installment')
                ->where('username', $username)
                ->where('publish', '1')
                ->orderBy('no', 'desc')
                ->first();

            $lastNumber = 0;
            if ($lastCicilan) {
                $lastCode = $lastCicilan->code;
                if (preg_match('/C(\d+)/', $lastCode, $matches)) {
                    $lastNumber = (int) $matches[1];
                }
            }

            $newNumber = $lastNumber + 1;
            $code = 'C' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

            // Upload attachment
            $attachmentPath = '';
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = 'hutang/bukti_cicilan/' . $username . '/' . $code . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if ($uploaded) {
                    $attachmentPath = $filename;
                }
            }

            DB::beginTransaction();

            try {
                // 1. Insert cicilan
                $cicilanData = [
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $code,
                    'code_debt' => $request->hutang_code,
                    'user' => $userName,
                    'account_category' => substr($request->source_account, 0, 1),
                    'account' => $request->source_account,
                    'account_related' => $hutang->account, // Akun hutang
                    'name' => $request->name,
                    'link' => 'pembayaran-cicilan-' . $code,
                    'description' => $request->description,
                    'value' => $request->amount,
                    'date_transaction' => date('Y-m-d', strtotime($request->transaction_date)),
                    'date' => now(),
                    'publish' => '1'
                ];

                if ($attachmentPath) {
                    $cicilanData['attachment'] = $attachmentPath;
                }

                $cicilanId = DB::table('keu_debt_installment')->insertGetId($cicilanData);

                // 2. PERBAIKAN: Buat jurnal otomatis untuk pembayaran cicilan
                // Jurnal: Hutang (Debit) - Kas/Bank (Credit)

                $hutangAccount = DB::table('keu_account')
                    ->where('username', $username)
                    ->where('code', $hutang->account)
                    ->first();

                // Debit: Hutang berkurang
                DB::table('keu_journal')->insert([
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $code,
                    'user' => $userName,
                    'status' => 'debit',
                    'account' => $hutang->account, // Hutang
                    'name' => $hutangAccount ? $hutangAccount->name : 'Hutang',
                    'description' => $request->description,
                    'value' => $request->amount,
                    'date_transaction' => date('Y-m-d', strtotime($request->transaction_date)),
                    'date' => now(),
                    'publish' => '1'
                ]);

                // Credit: Kas/Bank berkurang
                DB::table('keu_journal')->insert([
                    'username' => $username,
                    'subdomain' => $subdomain,
                    'code' => $code,
                    'user' => $userName,
                    'status' => 'credit',
                    'account' => $request->source_account, // Kas/Bank
                    'name' => $sourceAccount->name,
                    'description' => $request->description,
                    'value' => $request->amount,
                    'date_transaction' => date('Y-m-d', strtotime($request->transaction_date)),
                    'date' => now(),
                    'publish' => '1'
                ]);

                DB::commit();

                $sisaHutangSetelahCicilan = $sisaHutang - $request->amount;
                $statusHutang = $sisaHutangSetelahCicilan <= 0 ? 'LUNAS' : 'BELUM LUNAS';

                // Notifikasi
                DB::table('notifications')->insert([
                    'username' => $username,
                    'title' => 'Cicilan Hutang Ditambahkan',
                    'message' => sprintf(
                        'Cicilan hutang "%s" sebesar %s berhasil dicatat pada %s. Sisa hutang: %s. Status: %s',
                        $hutang->name,
                        'Rp ' . number_format($request->amount, 0, ',', '.'),
                        Carbon::parse($request->transaction_date)->locale('id')->translatedFormat('d F Y'),
                        'Rp ' . number_format($sisaHutangSetelahCicilan, 0, ',', '.'),
                        $statusHutang
                    ),
                    'is_read' => '0',
                    'icon' => 'money_dollar_circle_line',
                    'priority' => $sisaHutangSetelahCicilan <= 0 ? 'high' : 'normal',
                    'date' => now(),
                    'publish' => '1'
                ]);

                $newTotalCicilan = $totalCicilan + $request->amount;
                $attachmentUrl = null;
                if ($attachmentPath) {
                    $attachmentUrl = GoogleCloudStorageHelper::getFileUrl($attachmentPath);
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $cicilanId,
                        'code' => $code,
                        'hutang_code' => $request->hutang_code,
                        'name' => $request->name,
                        'description' => $request->description,
                        'amount' => $request->amount,
                        'formatted_amount' => 'Rp ' . number_format($request->amount, 0, ',', '.'),
                        'transaction_date' => date('d M Y', strtotime($request->transaction_date)),
                        'source_account' => [
                            'code' => $request->source_account,
                            'name' => $sourceAccount->name
                        ],
                        'total_hutang' => $hutang->value,
                        'formatted_total_hutang' => 'Rp ' . number_format($hutang->value, 0, ',', '.'),
                        'total_cicilan' => $newTotalCicilan,
                        'formatted_total_cicilan' => 'Rp ' . number_format($newTotalCicilan, 0, ',', '.'),
                        'sisa_hutang' => $sisaHutangSetelahCicilan,
                        'formatted_sisa_hutang' => 'Rp ' . number_format($sisaHutangSetelahCicilan, 0, ',', '.'),
                        'status' => $sisaHutangSetelahCicilan <= 0 ? 'Lunas' : 'Belum Lunas',
                        'attachment' => $attachmentUrl,
                        'attachment_path' => $attachmentPath
                    ],
                    'message' => 'Cicilan berhasil ditambahkan dengan jurnal otomatis'
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan cicilan: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getAccountsForHutang()
    {
        try {

            $username = auth()->user()->username;

            $sourceAccounts = DB::table('keu_account')
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

            $relatedAccounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', 'like', '1%')
                ->where('related', '1')
                ->where('publish', '1')
                ->where('type', 'I')
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

    public function getAccountsForTambahCicilan()
    {
        try {
            $username = auth()->user()->username;

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', 'like', '1%')
                ->where('related', '1')
                ->where('publish', '1')
                ->where('type', 'I')
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
                'message' => 'Data akun untuk tambah cicilan berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data akun untuk tambah cicilan: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function getLaporanHutang()
    {
        try {
            $username = auth()->user()->username;

            $daftarHutang = DB::table('keu_debt')
                ->select('name', 'description', 'date_transaction', 'date_deadline', 'value', 'code')
                ->where('username', $username)
                ->where('status', 'debit')
                ->where('publish', '1')
                ->orderBy('date_deadline', 'asc')
                ->get();

            $totalHutang = 0;
            $laporanHutang = [];

            foreach ($daftarHutang as $hutang) {
                $totalCicilan = DB::table('keu_debt_installment')
                    ->where('username', $username)
                    ->where('code_debt', $hutang->code)
                    ->where('publish', '1')
                    ->sum('value');

                $sisaHutang = $hutang->value - $totalCicilan;
                $totalHutang += $sisaHutang;

                $laporanHutang[] = [
                    'nama_penghutang' => $hutang->name,
                    'deskripsi' => $hutang->description ?: '',
                    'tanggal_hutang' => Carbon::parse($hutang->date_transaction)->locale('id')->translatedFormat('d F Y'),
                    'tanggal_jatuh_tempo' => Carbon::parse($hutang->date_deadline)->locale('id')->translatedFormat('d F Y'),
                    'total_hutang' => number_format($hutang->value, 0, ',', '.'),
                    'total_cicilan' => number_format($totalCicilan, 0, ',', '.'),
                    'sisa_hutang' => number_format(max(0, $sisaHutang), 0, ',', '.')
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_hutang' => number_format($totalHutang, 0, ',', '.'),
                    'daftar_hutang' => $laporanHutang
                ],
                'message' => 'Laporan hutang berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan hutang: ' . $e->getMessage()
            ], 500);
        }
    }
}