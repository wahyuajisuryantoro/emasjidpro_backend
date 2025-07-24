<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class BukuBesarController extends Controller
{
    public function getAccountBukuBesar(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('publish', '1')
                ->orderBy('code', 'asc')
                ->get(['code', 'name']);

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => $accounts,
                ],
                'message' => 'Data akun berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data akun: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getBukuBesarByAccount($code_account)
    {
        try {
            $username = auth()->user()->username;

            $account = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', $code_account)
                ->where('publish', '1')
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun tidak ditemukan'
                ], 404);
            }

            $journals = DB::table('keu_journal')
                ->where('username', $username)
                ->where('account', $code_account)
                ->where('publish', '1')
                ->orderBy('date_transaction', 'desc')
                ->orderBy('no', 'desc')
                ->get();

            $entries = $journals->map(function ($journal) {
                return [
                    'id' => $journal->no,
                    'code' => $journal->code,
                    'description' => $journal->description,
                    'status' => $journal->status,
                    'value' => $journal->value,
                    'formatted_value' => 'Rp ' . number_format($journal->value, 0, ',', '.'),
                    'date_transaction' => $journal->date_transaction,
                    'formatted_date' => Carbon::parse($journal->date_transaction)->format('d M Y'),
                    'user' => $journal->user,
                ];
            });

            $totalDebit = $journals->where('status', 'debit')->sum('value');
            $totalCredit = $journals->where('status', 'credit')->sum('value');
            $balance = $totalDebit - $totalCredit;

            return response()->json([
                'success' => true,
                'data' => [
                    'account' => [
                        'code' => $account->code,
                        'name' => $account->name,
                        'full_name' => $account->code . ' - ' . $account->name,
                    ],
                    'entries' => $entries,
                    'total_count' => $entries->count(),
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'balance' => $balance,
                    'formatted_total_debit' => 'Rp ' . number_format($totalDebit, 0, ',', '.'),
                    'formatted_total_credit' => 'Rp ' . number_format($totalCredit, 0, ',', '.'),
                    'formatted_balance' => 'Rp ' . number_format($balance, 0, ',', '.'),
                ],
                'message' => 'Data buku besar berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data buku besar: ' . $e->getMessage(),
            ], 500);
        }
    }
}
