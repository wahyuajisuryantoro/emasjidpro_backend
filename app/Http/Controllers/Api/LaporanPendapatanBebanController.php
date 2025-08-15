<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class LaporanPendapatanBebanController extends Controller
{
    public function getLaporanPendapatanBeban(Request $request)
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

            // Query transactions
            $transactionQuery = DB::table('keu_transaction')
                ->where('username', $username)
                ->where('publish', '1');

            if ($allData == 'true' || $allData == true) {
                // No date filter for all data
            } elseif ($fromMonth && $fromYear && $toMonth && $toYear) {
                $startDate = Carbon::createFromDate($fromYear, $fromMonth, 1)->startOfMonth();
                $endDate = Carbon::createFromDate($toYear, $toMonth, 1)->endOfMonth();
                $transactionQuery->whereBetween('date_transaction', [$startDate, $endDate]);
            } elseif ($month && $year) {
                $transactionQuery->whereYear('date_transaction', $year)
                    ->whereMonth('date_transaction', $month);
            } else {
                $transactionQuery->whereYear('date_transaction', date('Y'))
                    ->whereMonth('date_transaction', date('m'));
            }

            $transactions = $transactionQuery->get();

            // Get accounts
            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->whereIn('code_account_category', ['5', '6'])
                ->get()
                ->keyBy('code');

            // Group transactions by account code
            $pendapatanSummary = [];
            $bebanSummary = [];
            $totalPendapatan = 0;
            $totalBeban = 0;

            foreach ($transactions as $transaction) {
                $account = $accounts->get($transaction->account);
                if (!$account)
                    continue;

                $categoryCode = $account->code_account_category;
                $accountCode = $transaction->account;
                $accountName = $account->name;

                if ($categoryCode == '5') { // Pendapatan
                    if (!isset($pendapatanSummary[$accountCode])) {
                        $pendapatanSummary[$accountCode] = [
                            'account_code' => $accountCode,
                            'account_name' => $accountName,
                            'total_value' => 0
                        ];
                    }

                    if ($transaction->status == 'debit') {
                        $pendapatanSummary[$accountCode]['total_value'] += $transaction->value;
                        $totalPendapatan += $transaction->value;
                    }
                } elseif ($categoryCode == '6') { // Beban
                    if (!isset($bebanSummary[$accountCode])) {
                        $bebanSummary[$accountCode] = [
                            'account_code' => $accountCode,
                            'account_name' => $accountName,
                            'total_value' => 0
                        ];
                    }

                    if ($transaction->status == 'credit') {
                        $bebanSummary[$accountCode]['total_value'] += $transaction->value;
                        $totalBeban += $transaction->value;
                    }
                }
            }

            ksort($pendapatanSummary);
            ksort($bebanSummary);
            $labaRugi = $totalPendapatan - $totalBeban;
            $isLabaRugiPositif = $labaRugi >= 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'pendapatan' => array_values($pendapatanSummary),
                    'beban' => array_values($bebanSummary),
                    'total_pendapatan' => $totalPendapatan,
                    'total_beban' => $totalBeban,
                    'surplus_defisit' => $labaRugi,
                    'formatted_total_pendapatan' => 'Rp ' . number_format($totalPendapatan, 0, ',', '.'),
                    'formatted_total_beban' => 'Rp ' . number_format($totalBeban, 0, ',', '.'),
                    'formatted_surplus_defisit' => 'Rp ' . number_format(abs($labaRugi), 0, ',', '.'),
                    'is_surplus' => $isLabaRugiPositif,
                    'surplus_defisit_text' => $isLabaRugiPositif ? 'SURPLUS' : 'DEFISIT',
                    'pendapatan_bersih' => $labaRugi,
                    'formatted_pendapatan_bersih' => 'Rp ' . number_format(abs($labaRugi), 0, ',', '.'),
                    'total_pendapatan_count' => count($pendapatanSummary),
                    'total_beban_count' => count($bebanSummary),
                    'selected_month' => $month ? (int) $month : null,
                    'selected_year' => $year ? (int) $year : null,
                    'period_info' => $this->getPeriodInfo($request),
                ],
                'message' => 'Data laporan pendapatan dan beban berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data laporan pendapatan dan beban: ' . $e->getMessage(),
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
