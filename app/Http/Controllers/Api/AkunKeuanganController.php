<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class AkunKeuanganController extends Controller
{
    public function getAccountsKeuangan(Request $request)
    {
        try {
            // Ambil username dari user yang sedang login
            $user = $request->user();
            $username = $user->username;

            // Query data akun keuangan berdasarkan username
            $accounts = DB::table('keu_account')
                ->join('keu_account_category', 'keu_account.code_account_category', '=', 'keu_account_category.code')
                ->select(
                    'keu_account.no',
                    'keu_account.code',
                    'keu_account.name',
                    'keu_account.balance',
                    'keu_account.type',
                    'keu_account.cash_and_bank',
                    'keu_account.code_account_category',
                    'keu_account_category.name as category_name',
                    'keu_account_category.type as category_type'
                )
                ->where('keu_account.username', $username)
                ->where('keu_account.publish', '1')
                ->orderBy('keu_account.code')
                ->get();

            // Kelompokkan berdasarkan kategori
            $grouped_accounts = [
                'aktiva_lancar' => [],
                'aktiva_tetap' => [],
                'kewajiban' => [],
                'saldo' => [],
                'pendapatan' => [],
                'beban' => []
            ];

            $totals = [
                'total_aktiva_lancar' => 0,
                'total_aktiva_tetap' => 0,
                'total_kewajiban' => 0,
                'total_saldo' => 0,
                'total_pendapatan' => 0,
                'total_beban' => 0
            ];

            foreach ($accounts as $account) {
                $account_data = [
                    'no' => $account->no,
                    'kode' => $account->code,
                    'nama' => $account->name,
                    'kategori' => $account->category_name,
                    'saldo' => (int) $account->balance,
                    'type' => $account->type,
                    'cash_and_bank' => $account->cash_and_bank
                ];

                // Kelompokkan berdasarkan kode kategori
                switch ($account->code_account_category) {
                    case '1': // Aktiva Lancar
                        $grouped_accounts['aktiva_lancar'][] = $account_data;
                        $totals['total_aktiva_lancar'] += $account->balance;
                        break;
                    case '2': // Aktiva Tetap
                        $grouped_accounts['aktiva_tetap'][] = $account_data;
                        $totals['total_aktiva_tetap'] += $account->balance;
                        break;
                    case '3': // Kewajiban
                        $grouped_accounts['kewajiban'][] = $account_data;
                        $totals['total_kewajiban'] += $account->balance;
                        break;
                    case '4': // Saldo
                        $grouped_accounts['saldo'][] = $account_data;
                        $totals['total_saldo'] += $account->balance;
                        break;
                    case '5': // Pendapatan
                        $grouped_accounts['pendapatan'][] = $account_data;
                        $totals['total_pendapatan'] += $account->balance;
                        break;
                    case '6': // Beban
                        $grouped_accounts['beban'][] = $account_data;
                        $totals['total_beban'] += $account->balance;
                        break;
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Data akun keuangan berhasil diambil',
                'data' => [
                    'accounts' => $grouped_accounts,
                    'totals' => $totals
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data akun keuangan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAccountCategories(Request $request)
    {
        try {
            $categories = DB::table('keu_account_category')
                ->select('code', 'name', 'type')
                ->where('publish', '1')
                ->orderBy('code')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Data kategori akun berhasil diambil',
                'data' => $categories
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data kategori akun',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function storeAccount(Request $request)
    {
        try {
            $user = $request->user();
            $username = $user->username;

            // Validasi input - hapus type dan related dari required
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|max:10',
                'code_account_category' => 'required|string|max:10',
                'name' => 'required|string|max:50',
                'balance' => 'required|integer|min:0',
                'cash_and_bank' => 'required|in:0,1',
                'description' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Data tidak valid',
                    'errors' => $validator->errors()
                ], 422);
            }

            $code = $request->code;
            $codeAccountCategory = $request->code_account_category;
            $name = $request->name;
            $balance = $request->balance;
            $cashAndBank = $request->cash_and_bank;
            $description = $request->description ?? '';

            // Validasi prefix kode sesuai kategori
            $codePrefix = substr($code, 0, 1);
            if ($codePrefix !== $codeAccountCategory) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Kode akun harus diawali dengan {$codeAccountCategory} untuk kategori ini",
                    'error_type' => 'invalid_code_prefix'
                ], 422);
            }

            // Ambil data kategori untuk auto-populate type dan related
            $category = DB::table('keu_account_category')
                ->where('code', $codeAccountCategory)
                ->where('publish', '1')
                ->first();

            if (!$category) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Kategori akun tidak ditemukan',
                    'error_type' => 'category_not_found'
                ], 422);
            }

            // Validasi kode akun belum ada untuk username ini
            $codeExists = DB::table('keu_account')
                ->where('username', $username)
                ->where('code', $code)
                ->exists();

            if ($codeExists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Kode akun sudah digunakan',
                    'error_type' => 'code_already_exists'
                ], 422);
            }

            // Validasi cash_and_bank hanya untuk Aktiva Lancar (kategori 1)
            if ($codeAccountCategory !== '1' && $cashAndBank === '1') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cash and Bank hanya berlaku untuk akun Aktiva Lancar',
                    'error_type' => 'invalid_cash_and_bank'
                ], 422);
            }

            // Auto-populate type berdasarkan kategori
            $type = 'I'; // Default
            switch ($codeAccountCategory) {
                case '1': // Aktiva Lancar
                case '2': // Aktiva Tetap
                    $type = 'I'; // Income/Asset
                    break;
                case '3': // Kewajiban
                    $type = 'L'; // Liability
                    break;
                case '4': // Saldo
                    $type = 'NR'; // Net Revenue
                    break;
                case '5': // Pendapatan
                    $type = 'I'; // Income
                    break;
                case '6': // Beban
                    $type = 'O'; // Outcome/Expense
                    break;
            }

            // Auto-populate related - set 1 untuk akun penting, 0 untuk lainnya
            $related = ($codeAccountCategory === '1' || $codeAccountCategory === '2') ? '1' : '0';

            // Insert data akun baru
            $accountId = DB::table('keu_account')->insertGetId([
                'username' => $username,
                'subdomain' => '',
                'code' => $code,
                'code_account_category' => $codeAccountCategory,
                'cash_and_bank' => $cashAndBank,
                'name' => $name,
                'type' => $type,
                'balance' => $balance,
                'related' => $related,
                'description' => $description,
                'date' => now(),
                'publish' => '1'
            ]);

            // Ambil data akun yang baru dibuat untuk response
            $newAccount = DB::table('keu_account')
                ->join('keu_account_category', 'keu_account.code_account_category', '=', 'keu_account_category.code')
                ->select(
                    'keu_account.no',
                    'keu_account.code',
                    'keu_account.name',
                    'keu_account.balance',
                    'keu_account.type',
                    'keu_account.cash_and_bank',
                    'keu_account_category.name as category_name'
                )
                ->where('keu_account.no', $accountId)
                ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Akun keuangan berhasil ditambahkan',
                'data' => [
                    'no' => $newAccount->no,
                    'kode' => $newAccount->code,
                    'nama' => $newAccount->name,
                    'kategori' => $newAccount->category_name,
                    'saldo' => (int) $newAccount->balance,
                    'type' => $newAccount->type,
                    'cash_and_bank' => $newAccount->cash_and_bank
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menambahkan akun keuangan',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
