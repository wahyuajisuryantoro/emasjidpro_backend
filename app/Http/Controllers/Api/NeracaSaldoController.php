<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\JurnalKeuanganModel;
use App\Http\Controllers\Controller;

class NeracaSaldoController extends Controller
{
   public function getNeracaSaldo(Request $request)
    {
        try {
            $username = auth()->user()->username;
            $includeSystemDebt = $request->input('include_system_debt', true); 
            $includeSystemReceivable = $request->input('include_system_receivable', true); 
            
            $accounts = DB::table('keu_account as a')
                ->join('keu_account_category as c', 'a.code_account_category', '=', 'c.code')
                ->where(function($query) use ($username) {
                    $query->where('a.username', $username)
                          ->orWhere('a.username', '');
                })
                ->where('a.publish', '1')
                ->whereIn('c.type', ['NL', 'NR'])
                ->select(
                    'a.*',
                    'c.name as category_name',
                    'c.type as category_type'
                )
                ->orderBy('a.code', 'asc')
                ->get();
            $startOfYear = Carbon::createFromDate(date('Y'), 1, 1)->startOfDay();
            $today = Carbon::now()->endOfDay();

            $hutangCodes = DB::table('keu_debt')
                ->where(function($query) use ($username) {
                    $query->where('username', $username)
                          ->orWhere('username', '');
                })
                ->where('publish', '1')
                ->pluck('code')
                ->toArray();
                
            $piutangCodes = DB::table('keu_receivable')
                ->where(function($query) use ($username) {
                    $query->where('username', $username)
                          ->orWhere('username', '');
                })
                ->where('publish', '1')
                ->pluck('code')
                ->toArray();
                
            $cicilanHutangCodes = DB::table('keu_debt_installment')
                ->where(function($query) use ($username) {
                    $query->where('username', $username)
                          ->orWhere('username', '');
                })
                ->where('publish', '1')
                ->pluck('code')
                ->toArray();
                
            $cicilanPiutangCodes = DB::table('keu_receivable_installment')
                ->where(function($query) use ($username) {
                    $query->where('user', $username)
                          ->orWhere('user', '');
                })
                ->where('publish', '1')
                ->pluck('code')
                ->toArray();
        
            $excludeCodes = array_merge($hutangCodes, $piutangCodes, $cicilanHutangCodes, $cicilanPiutangCodes);
            
            $journals = DB::table('keu_journal')
                ->where(function($query) use ($username) {
                    $query->where('username', $username)
                          ->orWhere('username', '');
                })
                ->where('publish', '1')
                ->whereBetween('date_transaction', [$startOfYear, $today])
                ->whereNotIn('code', $excludeCodes)
                ->get();
            
            $journalsByAccount = $journals->groupBy('account');
            $assets = DB::table('keu_asset')
                ->where(function($query) use ($username) {
                    $query->where('username', $username)
                          ->orWhere('username', '');
                })
                ->where('publish', '1')
                ->get();
                
            $assetsByAccount = $assets->groupBy('account_related');
            $dataHutang = $this->getHutangData($username);
            $dataPiutang = $this->getPiutangData($username);
            $piutangAccounts = $this->detectPiutangAccounts($username);
            $hutangAccounts = $this->detectHutangAccounts($username);

            $neracaData = [
                'aktiva_lancar' => [],
                'aktiva_tetap' => [],
                'kewajiban' => [],
                'saldo' => []
            ];

            $totalLeft = 0;  // Total Aktiva
            $totalRight = 0; // Total Pasiva

            foreach ($accounts as $account) {
                $accountCode = $account->code;
                $saldoAwal = $account->balance;
                $accountJournals = $journalsByAccount->get($accountCode, collect());
                $totalDebit = $accountJournals->where('status', 'debit')->sum('value');
                $totalCredit = $accountJournals->where('status', 'credit')->sum('value');
                if ($account->category_type == 'NL') { // Aktiva
                    if ($account->code_account_category == '2') { 
                        $accountAssets = $assetsByAccount->get($accountCode, collect());
                        $totalAssetValue = $accountAssets->sum('value');
                        $totalDepreciation = $accountAssets->sum('depreciation');
                        $saldoAkhir = $totalAssetValue - $totalDepreciation;
                        
                        $neracaData['aktiva_tetap'][] = [
                            'account_code' => $accountCode,
                            'account_name' => $account->name,
                            'saldo_akhir' => $saldoAkhir,
                            'formatted_saldo_akhir' => 'Rp ' . number_format($saldoAkhir, 0, ',', '.'),
                        ];
                    } else {
                        // Aktiva Lancar - hitung dari saldo awal + mutasi jurnal
                        $saldoAkhir = $saldoAwal + $totalDebit - $totalCredit;
                        $accountAssets = $assetsByAccount->get($accountCode, collect());
                        if ($accountAssets->count() > 0) {
                            $totalAssetValue = $accountAssets->sum('value');
                            $totalDepreciation = $accountAssets->sum('depreciation');
                            $netAssetValue = $totalAssetValue - $totalDepreciation;
                            $saldoAkhir += $netAssetValue;
                        }
                        if (in_array($accountCode, $piutangAccounts) && $includeSystemReceivable) {
                            $saldoAkhir += $dataPiutang['total_piutang_bersih'];
                        }
                        
                        $neracaData['aktiva_lancar'][] = [
                            'account_code' => $accountCode,
                            'account_name' => $account->name,
                            'saldo_akhir' => $saldoAkhir,
                            'formatted_saldo_akhir' => 'Rp ' . number_format($saldoAkhir, 0, ',', '.'),
                        ];
                    }
                    
                    $totalLeft += $saldoAkhir;
                    
                } else { // NR (Pasiva) - semua dihitung dari jurnal
                    // NR (Pasiva): saldo_awal + credit - debit
                    $saldoAkhir = $saldoAwal + $totalCredit - $totalDebit;
                    if ($account->code_account_category == '3') { // Kewajiban
                        if (isset($hutangAccounts[$accountCode]) && $includeSystemDebt) {
                            $saldoAkhir += $hutangAccounts[$accountCode];
                        }
                        
                        $neracaData['kewajiban'][] = [
                            'account_code' => $accountCode,
                            'account_name' => $account->name,
                            'saldo_akhir' => $saldoAkhir,
                            'formatted_saldo_akhir' => 'Rp ' . number_format($saldoAkhir, 0, ',', '.'),
                        ];
                    } elseif ($account->code_account_category == '4') { // Modal/Saldo
                        $neracaData['saldo'][] = [
                            'account_code' => $accountCode,
                            'account_name' => $account->name,
                            'saldo_akhir' => $saldoAkhir,
                            'formatted_saldo_akhir' => 'Rp ' . number_format($saldoAkhir, 0, ',', '.'),
                        ];
                    }
                    
                    $totalRight += $saldoAkhir;
                }
            }

            // Count total accounts
            $totalAccountsLeft = count($neracaData['aktiva_lancar']) + count($neracaData['aktiva_tetap']);
            $totalAccountsRight = count($neracaData['kewajiban']) + count($neracaData['saldo']);

            return response()->json([
                'success' => true,
                'data' => [
                    'aktiva_lancar' => $neracaData['aktiva_lancar'],
                    'aktiva_tetap' => $neracaData['aktiva_tetap'],
                    'kewajiban' => $neracaData['kewajiban'],
                    'saldo' => $neracaData['saldo'],
                    'total_left' => $totalLeft,
                    'total_right' => $totalRight,
                    'formatted_total_left' => 'Rp ' . number_format($totalLeft, 0, ',', '.'),
                    'formatted_total_right' => 'Rp ' . number_format($totalRight, 0, ',', '.'),
                    'total_accounts_left' => $totalAccountsLeft,
                    'total_accounts_right' => $totalAccountsRight,
                    'period_info' => 'Per ' . Carbon::now()->format('d F Y'),
                    
                ],
                'message' => 'Data neraca berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data neraca: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    private function detectPiutangAccounts($username)
    {
        $piutangAccounts = DB::table('keu_receivable')
            ->where('username', $username)
            ->where('publish', '1')
            ->pluck('account')
            ->unique()
            ->values()
            ->toArray();

        return $piutangAccounts;
    }

    private function detectHutangAccounts($username)
    {
        $dataHutang = DB::table('keu_debt')
            ->select('code', 'value', 'account')
            ->where('username', $username)
            ->where('status', 'debit')
            ->where('publish', '1')
            ->get();

        $hutangPerAkun = [];

        foreach ($dataHutang as $hutang) {
            $pembayaran = DB::table('keu_debt_installment')
                ->where('username', $username)
                ->where('code_debt', $hutang->code)
                ->where('publish', '1')
                ->sum('value');

            $sisaHutang = $hutang->value - $pembayaran;
            if (!isset($hutangPerAkun[$hutang->account])) {
                $hutangPerAkun[$hutang->account] = 0;
            }
            $hutangPerAkun[$hutang->account] += $sisaHutang;
        }

        return $hutangPerAkun;
    }

    private function getHutangData($username)
    {
        $dataHutang = DB::table('keu_debt')
            ->select('code', 'value', 'account')
            ->where('username', $username)
            ->where('status', 'debit')
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

        return [
            'total_hutang_bruto' => $totalHutangBruto,
            'total_hutang_terbayar' => $totalHutangTerbayar,
            'total_hutang_bersih' => $totalHutangBruto - $totalHutangTerbayar,
        ];
    }
    private function getPiutangData($username)
    {
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

        return [
            'total_piutang_bruto' => $totalPiutangBruto,
            'total_piutang_terbayar' => $totalPiutangTerbayar,
            'total_piutang_bersih' => $totalPiutangBruto - $totalPiutangTerbayar,
        ];
    }

    private function getPeriodInfo($request)
    {
        $allData = $request->input('all_data', false);
        $month = $request->input('month');
        $year = $request->input('year');
        $fromMonth = $request->input('from_month');
        $fromYear = $request->input('from_year');
        $toMonth = $request->input('to_month');
        $toYear = $request->input('to_year');

        if ($allData == 'true' || $allData == true) {
            return 'Semua Data';
        } elseif ($fromMonth && $fromYear && $toMonth && $toYear) {
            $months = [
                1 => 'Januari',
                2 => 'Februari',
                3 => 'Maret',
                4 => 'April',
                5 => 'Mei',
                6 => 'Juni',
                7 => 'Juli',
                8 => 'Agustus',
                9 => 'September',
                10 => 'Oktober',
                11 => 'November',
                12 => 'Desember'
            ];

            $fromMonthName = $months[(int) $fromMonth] ?? '';
            $toMonthName = $months[(int) $toMonth] ?? '';

            if ($fromYear == $toYear && $fromMonth == $toMonth) {
                return "$fromMonthName $fromYear";
            } else {
                return "$fromMonthName $fromYear - $toMonthName $toYear";
            }
        } elseif ($month && $year) {
            $months = [
                1 => 'Januari',
                2 => 'Februari',
                3 => 'Maret',
                4 => 'April',
                5 => 'Mei',
                6 => 'Juni',
                7 => 'Juli',
                8 => 'Agustus',
                9 => 'September',
                10 => 'Oktober',
                11 => 'November',
                12 => 'Desember'
            ];
            $monthName = $months[(int) $month] ?? '';
            return "$monthName $year";
        }

        return 'Periode tidak diketahui';
    }
}