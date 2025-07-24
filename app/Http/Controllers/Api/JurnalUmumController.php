<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\JurnalKeuanganModel;
use App\Http\Controllers\Controller;

class JurnalUmumController extends Controller
{
   public function getJurnalUmum(Request $request)
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
            
            $query = JurnalKeuanganModel::where('username', $username)
                ->where('publish', '1');

            if ($allData == 'true' || $allData == true) {
            } elseif ($fromMonth && $fromYear && $toMonth && $toYear) {
                $startDate = Carbon::createFromDate($fromYear, $fromMonth, 1)->startOfMonth();
                $endDate = Carbon::createFromDate($toYear, $toMonth, 1)->endOfMonth();
                $query->whereBetween('date_transaction', [$startDate, $endDate]);
            } elseif ($month && $year) {
                $query->whereYear('date_transaction', $year)
                      ->whereMonth('date_transaction', $month);
            } else {
                $query->whereYear('date_transaction', date('Y'))
                      ->whereMonth('date_transaction', date('m'));
            }

            $query->orderBy('date_transaction', 'desc')
                  ->orderBy('no', 'desc');

            $journals = $query->get();

            $transactionGroups = [];

            foreach ($journals as $journal) {
                if (!isset($transactionGroups[$journal->code])) {
                    $transactionGroups[$journal->code] = [
                        'code' => $journal->code,
                        'date_transaction' => $journal->date_transaction->format('Y-m-d'),
                        'formatted_date' => $journal->date_transaction->format('d M Y'),
                        'entries' => [],
                        'total_value' => 0
                    ];
                }

                $accountDetails = DB::table('keu_account')
                    ->where('code', $journal->account)
                    ->where('username', $username)
                    ->first();

                $accountName = $accountDetails ? $accountDetails->name : $journal->name;

                $transactionGroups[$journal->code]['entries'][] = [
                    'id' => $journal->no,
                    'account' => $journal->account,
                    'account_name' => $accountName,
                    'description' => $journal->description,
                    'status' => $journal->status,
                    'value' => $journal->value,
                    'formatted_value' => 'Rp ' . number_format($journal->value, 0, ',', '.'),
                    'debit_value' => $journal->status === 'debit' ? $journal->value : 0,
                    'credit_value' => $journal->status === 'credit' ? $journal->value : 0,
                    'formatted_debit' => $journal->status === 'debit' ? 'Rp ' . number_format($journal->value, 0, ',', '.') : '',
                    'formatted_credit' => $journal->status === 'credit' ? 'Rp ' . number_format($journal->value, 0, ',', '.') : '',
                ];

            
                $transactionGroups[$journal->code]['total_value'] = max(
                    $transactionGroups[$journal->code]['total_value'],
                    $journal->value
                );
            }

            $result = array_values($transactionGroups);

            $totalDebit = $journals->where('status', 'debit')->sum('value');
            $totalCredit = $journals->where('status', 'credit')->sum('value');

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->orderBy('code', 'asc')
                ->get(['code', 'name'])
                ->map(function ($account) {
                    return [
                        'code' => $account->code,
                        'name' => $account->code . ' - ' . $account->name,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'journal_entries' => $result,
                    'total_count' => count($result),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'formatted_total_debit' => 'Rp ' . number_format($totalDebit, 0, ',', '.'),
                    'formatted_total_credit' => 'Rp ' . number_format($totalCredit, 0, ',', '.'),
                    'accounts' => $accounts,
                    'selected_month' => $month ? (int) $month : null,
                    'selected_year' => $year ? (int) $year : null,
                    'period_info' => $this->getPeriodInfo($request),
                ],
                'message' => 'Data jurnal umum berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data jurnal umum: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }
    public function getDetailJurnal(Request $request, $code)
    {
        try {
            $username = auth()->user()->username;

            $journals = JurnalKeuanganModel::where('username', $username)
                ->where('code', $code)
                ->where('publish', '1')
                ->get();

            if ($journals->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data jurnal tidak ditemukan'
                ], 404);
            }

            $entries = [];
            $totalValue = 0;
            $dateTransaction = null;

            foreach ($journals as $journal) {

                $accountDetails = DB::table('keu_account')
                    ->where('code', $journal->account)
                    ->where('username', $username)
                    ->first();

                $accountName = $accountDetails ? $accountDetails->name : $journal->name;

                $entries[] = [
                    'id' => $journal->no,
                    'account' => $journal->account,
                    'account_name' => $accountName,
                    'description' => $journal->description,
                    'status' => $journal->status,
                    'value' => $journal->value,
                    'formatted_value' => 'Rp ' . number_format($journal->value, 0, ',', '.'),
                    'debit_value' => $journal->status === 'debit' ? $journal->value : 0,
                    'credit_value' => $journal->status === 'credit' ? $journal->value : 0,
                    'formatted_debit' => $journal->status === 'debit' ? 'Rp ' . number_format($journal->value, 0, ',', '.') : '',
                    'formatted_credit' => $journal->status === 'credit' ? 'Rp ' . number_format($journal->value, 0, ',', '.') : '',
                ];

                $totalValue = max($totalValue, $journal->value);

                if (!$dateTransaction) {
                    $dateTransaction = $journal->date_transaction;
                }
            }


            $transaction = DB::table('keu_transaction')
                ->where('username', $username)
                ->where('code', $code)
                ->where('publish', '1')
                ->first();

            $transactionData = null;
            if ($transaction) {
                $transactionData = [
                    'id' => $transaction->no,
                    'name' => $transaction->name,
                    'description' => $transaction->description,
                    'value' => $transaction->value,
                    'formatted_value' => 'Rp ' . number_format($transaction->value, 0, ',', '.'),
                    'picture' => $transaction->picture ? url('storage/transactions/' . $transaction->picture) : null,
                    'status' => $transaction->status,
                    'account' => $transaction->account,
                    'account_related' => $transaction->account_related,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'code' => $code,
                    'date_transaction' => $dateTransaction->format('Y-m-d'),
                    'formatted_date' => $dateTransaction->format('d M Y'),
                    'entries' => $entries,
                    'total_debit' => $journals->where('status', 'debit')->sum('value'),
                    'total_credit' => $journals->where('status', 'credit')->sum('value'),
                    'formatted_total_debit' => 'Rp ' . number_format($journals->where('status', 'debit')->sum('value'), 0, ',', '.'),
                    'formatted_total_credit' => 'Rp ' . number_format($journals->where('status', 'credit')->sum('value'), 0, ',', '.'),
                    'transaction' => $transactionData,
                ],
                'message' => 'Detail jurnal berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail jurnal: ' . $e->getMessage(),
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
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
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
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
            ];
            $monthName = $months[(int) $month] ?? '';
            return "$monthName $year";
        }
        
        return 'Periode tidak diketahui';
    }
}