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
            $month = $request->input('month');
            $year = $request->input('year');
            $allData = $request->input('all_data', false);
            $fromMonth = $request->input('from_month');
            $fromYear = $request->input('from_year');
            $toMonth = $request->input('to_month');
            $toYear = $request->input('to_year');

            // Get accounts
            $accounts = DB::table('keu_account as a')
                ->join('keu_account_category as c', 'a.code_account_category', '=', 'c.code')
                ->where('a.username', $username)
                ->where('a.publish', '1')
                ->whereIn('c.type', ['NL', 'NR'])
                ->select(
                    'a.*',
                    'c.name as category_name',
                    'c.type as category_type'
                )
                ->orderBy('a.code', 'asc')
                ->get();

            // Get journal data with date filter
            $journalQuery = JurnalKeuanganModel::where('username', $username)
                ->where('publish', '1');

            if ($allData == 'true' || $allData == true) {
                // No date filter for all data
            } elseif ($fromMonth && $fromYear && $toMonth && $toYear) {
                $startDate = Carbon::createFromDate($fromYear, $fromMonth, 1)->startOfMonth();
                $endDate = Carbon::createFromDate($toYear, $toMonth, 1)->endOfMonth();
                $journalQuery->whereBetween('date_transaction', [$startDate, $endDate]);
            } elseif ($month && $year) {
                $journalQuery->whereYear('date_transaction', $year)
                    ->whereMonth('date_transaction', $month);
            } else {
                $journalQuery->whereYear('date_transaction', date('Y'))
                    ->whereMonth('date_transaction', date('m'));
            }

            $journals = $journalQuery->get();
            $journalsByAccount = $journals->groupBy('account');

            // Get assets data for Aktiva Tetap
            $assetsQuery = DB::table('keu_asset')
                ->where('username', $username)
                ->where('publish', '1');

            // Apply same date filter to assets
            if ($allData == 'true' || $allData == true) {
                // No date filter for all data
            } elseif ($fromMonth && $fromYear && $toMonth && $toYear) {
                $startDate = Carbon::createFromDate($fromYear, $fromMonth, 1)->startOfMonth();
                $endDate = Carbon::createFromDate($toYear, $toMonth, 1)->endOfMonth();
                $assetsQuery->whereBetween('date_purchase', [$startDate, $endDate]);
            } elseif ($month && $year) {
                $assetsQuery->whereYear('date_purchase', $year)
                    ->whereMonth('date_purchase', $month);
            } else {
                $assetsQuery->whereYear('date_purchase', date('Y'))
                    ->whereMonth('date_purchase', date('m'));
            }

            $assets = $assetsQuery->get();
            $assetsByAccount = $assets->groupBy('account_related');

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

                // Calculate balance based on account type
                if ($account->category_type == 'NL') { // Aktiva
                    // NL (Aktiva): saldo_awal + debit - credit
                    $saldoAkhir = $saldoAwal + $totalDebit - $totalCredit;

                    // For Aktiva Tetap (category 2), add asset values
                    if ($account->code_account_category == '2') {
                        $accountAssets = $assetsByAccount->get($accountCode, collect());
                        $totalAssetValue = $accountAssets->sum('value');
                        $totalDepreciation = $accountAssets->sum('depreciation');
                        $netAssetValue = $totalAssetValue - $totalDepreciation;
                        $saldoAkhir += $netAssetValue;
                    }

                    $totalLeft += $saldoAkhir;

                    if ($account->code_account_category == '1') { // Aktiva Lancar
                        $neracaData['aktiva_lancar'][] = [
                            'account_code' => $accountCode,
                            'account_name' => $account->name,
                            'saldo_akhir' => $saldoAkhir,
                            'formatted_saldo_akhir' => 'Rp ' . number_format($saldoAkhir, 0, ',', '.'),
                        ];
                    } elseif ($account->code_account_category == '2') { // Aktiva Tetap
                        $neracaData['aktiva_tetap'][] = [
                            'account_code' => $accountCode,
                            'account_name' => $account->name,
                            'saldo_akhir' => $saldoAkhir,
                            'formatted_saldo_akhir' => 'Rp ' . number_format($saldoAkhir, 0, ',', '.'),
                        ];
                    }
                } else { // NR (Pasiva)
                    // NR (Pasiva): saldo_awal + credit - debit
                    $saldoAkhir = $saldoAwal + $totalCredit - $totalDebit;
                    $totalRight += $saldoAkhir;

                    if ($account->code_account_category == '3') { // Kewajiban
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
                    'selected_month' => $month ? (int) $month : null,
                    'selected_year' => $year ? (int) $year : null,
                    'period_info' => $this->getPeriodInfo($request),
                ],
                'message' => 'Data neraca saldo berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data neraca saldo: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
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
