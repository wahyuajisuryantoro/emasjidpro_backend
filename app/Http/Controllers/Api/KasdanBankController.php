<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\TransaksiModel;
use App\Models\AkunKeuanganModel;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Helpers\GoogleCloudStorageHelper;
use Illuminate\Support\Facades\Validator;

class KasdanBankController extends Controller
{
    public function getDashboardKasdanBank()
    {
        $username = auth()->user()->username;

        $accounts = AkunKeuanganModel::where('username', $username)
            ->where('code_account_category', '1')
            ->where('cash_and_bank', '1')
            ->where('publish', '1')
            ->get();

        $mappedAccounts = $accounts->map(function ($account) {
            $type = (strpos(strtolower($account->name), 'kas') !== false) ? 'kas' : 'bank';

            return [
                'id' => $account->no,
                'name' => $account->name,
                'code' => $account->code,
                'balance' => (double) $account->balance,
                'type' => $type
            ];
        });

        $totalBalance = $accounts->sum('balance');

        $transactions = DB::table('keu_journal as kj')
            ->select(
                'kj.code',
                'kj.date_transaction as date',
                'kj.description',
                DB::raw('MAX(CASE WHEN kj.status = "debit" THEN a1.name END) as toAccount'),
                DB::raw('MAX(CASE WHEN kj.status = "credit" THEN a2.name END) as fromAccount'),
                DB::raw('MAX(kj.value) as amount')
            )
            ->join('keu_account as a1', 'kj.account', '=', 'a1.code')
            ->join('keu_account as a2', 'kj.account', '=', 'a2.code')
            ->where('kj.username', $username)
            ->where('a1.cash_and_bank', '1')
            ->where('a2.cash_and_bank', '1')
            ->where('kj.publish', '1')
            ->groupBy('kj.code', 'kj.date_transaction', 'kj.description')
            ->orderBy('kj.date_transaction', 'desc')
            ->limit(10)
            ->get();

        $mappedTransactions = $transactions->map(function ($item) {
            return [
                'id' => $item->code,
                'code' => $item->code,
                'fromAccount' => $item->fromAccount,
                'toAccount' => $item->toAccount,
                'amount' => (double) $item->amount,
                'date' => $item->date,
                'description' => $item->description,
                'status' => 'completed'
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'accounts' => $mappedAccounts,
                'totalBalance' => $totalBalance,
                'transactions' => $mappedTransactions
            ]
        ]);
    }

    public function getAllDataKasdanBank()
    {
        try {
            $username = auth()->user()->username;

            // Ambil semua akun kas dan bank
            $accounts = AkunKeuanganModel::where('username', $username)
                ->where('code_account_category', '1')
                ->where('cash_and_bank', '1')
                ->where('publish', '1')
                ->orderBy('code')
                ->get();

            $mappedAccounts = $accounts->map(function ($account) {
                $type = (strpos(strtolower($account->name), 'kas') !== false) ? 'kas' : 'bank';

                return [
                    'id' => $account->no,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $type,
                    'balance' => (double) $account->balance,
                    'formatted_balance' => 'Rp ' . number_format($account->balance, 0, ',', '.')
                ];
            });

            // Hitung total saldo
            $totalBalance = $accounts->sum('balance');

            // Ambil semua transaksi kas dan bank (journal entries)
            $transactions = DB::table('keu_journal as kj')
                ->select(
                    'kj.code',
                    'kj.date_transaction as date',
                    'kj.description',
                    'kj.value as amount',
                    'kj.status',
                    'kj.account',
                    'kj.name as account_name'
                )
                ->join('keu_account as ka', 'kj.account', '=', 'ka.code')
                ->where('kj.username', $username)
                ->where('ka.cash_and_bank', '1')
                ->where('kj.publish', '1')
                ->orderBy('kj.date_transaction', 'desc')
                ->orderBy('kj.code', 'desc')
                ->get();

            // Group transaksi berdasarkan code untuk mendapatkan transfer
            $groupedTransactions = $transactions->groupBy('code')->map(function ($items) {
                $debit = $items->where('status', 'debit')->first();
                $credit = $items->where('status', 'credit')->first();

                return [
                    'code' => $items->first()->code,
                    'date' => $items->first()->date,
                    'description' => $items->first()->description,
                    'amount' => (double) $items->first()->amount,
                    'formatted_amount' => 'Rp ' . number_format($items->first()->amount, 0, ',', '.'),
                    'from_account' => $credit ? [
                        'code' => $credit->account,
                        'name' => $credit->account_name
                    ] : null,
                    'to_account' => $debit ? [
                        'code' => $debit->account,
                        'name' => $debit->account_name
                    ] : null,
                    'type' => $this->determineTransactionType($debit, $credit)
                ];
            })->values();

            // Statistik bulanan
            $currentMonth = now()->month;
            $currentYear = now()->year;

            $monthlyStats = DB::table('keu_journal as kj')
                ->join('keu_account as ka', 'kj.account', '=', 'ka.code')
                ->where('kj.username', $username)
                ->where('ka.cash_and_bank', '1')
                ->where('kj.publish', '1')
                ->whereMonth('kj.date_transaction', $currentMonth)
                ->whereYear('kj.date_transaction', $currentYear)
                ->selectRaw('
                COUNT(DISTINCT kj.code) as total_transactions,
                SUM(CASE WHEN kj.status = "debit" THEN kj.value ELSE 0 END) as total_debit,
                SUM(CASE WHEN kj.status = "credit" THEN kj.value ELSE 0 END) as total_credit
            ')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => $mappedAccounts,
                    'total_balance' => (double) $totalBalance,
                    'formatted_total_balance' => 'Rp ' . number_format($totalBalance, 0, ',', '.'),
                    'transactions' => $groupedTransactions,
                    'monthly_stats' => [
                        'month' => now()->locale('id')->translatedFormat('F Y'),
                        'total_transactions' => (int) $monthlyStats->total_transactions ?? 0,
                        'total_debit' => (double) $monthlyStats->total_debit ?? 0,
                        'total_credit' => (double) $monthlyStats->total_credit ?? 0,
                        'formatted_total_debit' => 'Rp ' . number_format($monthlyStats->total_debit ?? 0, 0, ',', '.'),
                        'formatted_total_credit' => 'Rp ' . number_format($monthlyStats->total_credit ?? 0, 0, ',', '.')
                    ]
                ],
                'message' => 'Data kas dan bank berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data kas dan bank: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getAccountsForKasdanBank()
    {
        try {
            $username = auth()->user()->username;
            $bankAccounts = AkunKeuanganModel::where('username', $username)
                ->where('code_account_category', '1')
                ->where('cash_and_bank', '1')
                ->where('publish', '1')
                ->where('code', '!=', '101')
                ->orderBy('name')
                ->get();

            $mappedBankAccounts = $bankAccounts->map(function ($account) {
                return [
                    'code' => $account->code,
                    'name' => $account->name,
                    'balance' => (double) $account->balance
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'bank_accounts' => $mappedBankAccounts
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load accounts: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getBankAccountsForTransfer()
    {
        try {
            $username = auth()->user()->username;
            $bankAccounts = AkunKeuanganModel::where('username', $username)
                ->where('code_account_category', '1')
                ->where('cash_and_bank', '1')
                ->where('publish', '1')
                ->where('code', '!=', '101')
                ->orderBy('name')
                ->get();

            $mappedBankAccounts = $bankAccounts->map(function ($account) {
                return [
                    'code' => $account->code,
                    'name' => $account->name,
                    'balance' => (double) $account->balance,
                    'can_transfer_from' => $account->balance > 0,
                    'can_transfer_to' => true
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'bank_accounts' => $mappedBankAccounts
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load bank accounts: ' . $e->getMessage()
            ], 500);
        }
    }

    // public function getKasBalance()
    // {
    //     try {
    //         $username = auth()->user()->username;
    //         $kasAccount = DB::table('keu_account')
    //             ->where('code', '101')
    //             ->where('username', $username)
    //             ->first();

    //         return response()->json([
    //             'success' => true,
    //             'data' => [
    //                 'kas_balance' => $kasAccount ? (double)$kasAccount->balance : 0
    //             ]
    //         ]);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to load kas balance: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function setorKasdanBank(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_transaction' => 'required|date',
            'bank_code' => 'required|string|exists:keu_account,code',
            'value' => 'required|numeric|min:1',
            'description' => 'required|string|max:200',
            'picture' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = auth()->user();
            $username = $user->username;

            $kasAccount = DB::table('keu_account')
                ->where('code', '101')
                ->where('username', $username)
                ->first();

            if (!$kasAccount || $kasAccount->balance < (int) $request->value) {
                throw new \Exception('Saldo kas tidak mencukupi');
            }

            $bankAccount = DB::table('keu_account')
                ->where('code', $request->bank_code)
                ->where('username', $username)
                ->first();

            if (!$bankAccount) {
                throw new \Exception('Bank account not found');
            }

            $today = date('Ymd');
            $lastTransaction = DB::table('keu_journal')
                ->where('username', $username)
                ->where('code', 'like', $today . '%')
                ->orderBy('code', 'desc')
                ->first();

            $sequence = '001';
            if ($lastTransaction) {
                $lastSequence = (int) substr($lastTransaction->code, -3);
                $sequence = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);
            }

            $transactionCode = $today . $sequence;

            $picturePath = '';
            if ($request->hasFile('picture')) {
                $file = $request->file('picture');
                $filename = 'kas_dan_bank/setor/' . $username . '/' . $transactionCode . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if ($uploaded) {
                    $picturePath = $filename;
                }
            }
            DB::table('keu_journal')->insert([
                'username' => $username,
                'subdomain' => '',
                'code' => $transactionCode,
                'user' => $user->name,
                'status' => 'debit',
                'account' => $request->bank_code,
                'name' => $bankAccount->name,
                'description' => 'Setor Kas - ' . $request->description,
                'value' => (int) $request->value,
                'date_transaction' => Carbon::parse($request->date_transaction)->format('Y-m-d'),
                'date' => now(),
                'publish' => '1'
            ]);
            DB::table('keu_journal')->insert([
                'username' => $username,
                'subdomain' => '',
                'code' => $transactionCode,
                'user' => $user->name,
                'status' => 'credit',
                'account' => '101',
                'name' => $kasAccount->name,
                'description' => 'Setor Kas - ' . $request->description,
                'value' => (int) $request->value,
                'date_transaction' => Carbon::parse($request->date_transaction)->format('Y-m-d'),
                'date' => now(),
                'publish' => '1'
            ]);
            DB::table('keu_account')
                ->where('code', '101')
                ->where('username', $username)
                ->decrement('balance', (int) $request->value);

            DB::table('keu_account')
                ->where('code', $request->bank_code)
                ->where('username', $username)
                ->increment('balance', (int) $request->value);

            DB::commit();

            DB::table('notifications')->insert([
                'username' => $username,
                'title' => 'Setor Kas ke Bank',
                'message' => sprintf(
                    'Setor kas ke %s sebesar %s berhasil dicatat pada %s. Kode transaksi: %s',
                    $bankAccount->name,
                    'Rp ' . number_format($request->value, 0, ',', '.'),
                    Carbon::parse($request->date_transaction)->locale('id')->translatedFormat('d F Y'),
                    $transactionCode
                ),
                'is_read' => '0',
                'icon' => 'bank_line',
                'priority' => $request->value >= 1000000 ? 'high' : 'normal',
                'date' => now(),
                'publish' => '1'
            ]);

            $pictureUrl = null;
            if ($picturePath) {
                $pictureUrl = GoogleCloudStorageHelper::getFileUrl($picturePath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Setor berhasil disimpan',
                'data' => [
                    'transaction_code' => $transactionCode,
                    'bank_name' => $bankAccount->name,
                    'amount' => (int) $request->value,
                    'formatted_amount' => 'Rp ' . number_format($request->value, 0, ',', '.'),
                    'new_bank_balance' => DB::table('keu_account')
                        ->where('code', $request->bank_code)
                        ->where('username', $username)
                        ->value('balance'),
                    'new_kas_balance' => DB::table('keu_account')
                        ->where('code', '101')
                        ->where('username', $username)
                        ->value('balance'),
                    'picture' => $pictureUrl,
                    'picture_path' => $picturePath
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Setor failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function tarikKasDanBank(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_transaction' => 'required|date',
            'bank_code' => 'required|string|exists:keu_account,code',
            'value' => 'required|numeric|min:1',
            'description' => 'required|string|max:200',
            'picture' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = auth()->user();
            $username = $user->username;

            $bankAccount = DB::table('keu_account')
                ->where('code', $request->bank_code)
                ->where('username', $username)
                ->first();

            if (!$bankAccount) {
                throw new \Exception('Bank account not found');
            }

            if ($bankAccount->balance < (int) $request->value) {
                throw new \Exception('Saldo bank tidak mencukupi');
            }

            $kasAccount = DB::table('keu_account')
                ->where('code', '101')
                ->where('username', $username)
                ->first();

            $today = date('Ymd');
            $lastTransaction = DB::table('keu_journal')
                ->where('username', $username)
                ->where('code', 'like', $today . '%')
                ->orderBy('code', 'desc')
                ->first();

            $sequence = '001';
            if ($lastTransaction) {
                $lastSequence = (int) substr($lastTransaction->code, -3);
                $sequence = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);
            }

            $transactionCode = $today . $sequence;

            $picturePath = '';
            if ($request->hasFile('picture')) {
                $file = $request->file('picture');
                $filename = 'kas_dan_bank/tarik/' . $username . '/' . $transactionCode . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if ($uploaded) {
                    $picturePath = $filename;
                }
            }

            DB::table('keu_journal')->insert([
                'username' => $username,
                'subdomain' => '',
                'code' => $transactionCode,
                'user' => $user->name,
                'status' => 'debit',
                'account' => '101',
                'name' => $kasAccount->name,
                'description' => 'Tarik Dana - ' . $request->description,
                'value' => (int) $request->value,
                'date_transaction' => Carbon::parse($request->date_transaction)->format('Y-m-d'),
                'date' => now(),
                'publish' => '1'
            ]);

            DB::table('keu_journal')->insert([
                'username' => $username,
                'subdomain' => '',
                'code' => $transactionCode,
                'user' => $user->name,
                'status' => 'credit',
                'account' => $request->bank_code,
                'name' => $bankAccount->name,
                'description' => 'Tarik Dana - ' . $request->description,
                'value' => (int) $request->value,
                'date_transaction' => Carbon::parse($request->date_transaction)->format('Y-m-d'),
                'date' => now(),
                'publish' => '1'
            ]);

            DB::table('keu_account')
                ->where('code', $request->bank_code)
                ->where('username', $username)
                ->decrement('balance', (int) $request->value);

            DB::table('keu_account')
                ->where('code', '101')
                ->where('username', $username)
                ->increment('balance', (int) $request->value);

            DB::commit();

            DB::table('notifications')->insert([
                'username' => $username,
                'title' => 'Tarik Dana dari Bank',
                'message' => sprintf(
                    'Tarik dana dari %s sebesar %s berhasil dicatat pada %s. Kode transaksi: %s',
                    $bankAccount->name,
                    'Rp ' . number_format($request->value, 0, ',', '.'),
                    Carbon::parse($request->date_transaction)->locale('id')->translatedFormat('d F Y'),
                    $transactionCode
                ),
                'is_read' => '0',
                'icon' => 'money_dollar_box_line',
                'priority' => $request->value >= 1000000 ? 'high' : 'normal',
                'date' => now(),
                'publish' => '1'
            ]);

            $pictureUrl = null;
            if ($picturePath) {
                $pictureUrl = GoogleCloudStorageHelper::getFileUrl($picturePath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Tarik berhasil disimpan',
                'data' => [
                    'transaction_code' => $transactionCode,
                    'bank_name' => $bankAccount->name,
                    'amount' => (int) $request->value,
                    'formatted_amount' => 'Rp ' . number_format($request->value, 0, ',', '.'),
                    'new_bank_balance' => DB::table('keu_account')
                        ->where('code', $request->bank_code)
                        ->where('username', $username)
                        ->value('balance'),
                    'new_kas_balance' => DB::table('keu_account')
                        ->where('code', '101')
                        ->where('username', $username)
                        ->value('balance'),
                    'picture' => $pictureUrl,
                    'picture_path' => $picturePath
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Tarik failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function transferKasdanBank(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_transaction' => 'required|date',
            'from_bank_code' => 'required|string|exists:keu_account,code',
            'to_bank_code' => 'required|string|exists:keu_account,code|different:from_bank_code',
            'value' => 'required|numeric|min:1',
            'description' => 'required|string|max:200',
            'picture' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = auth()->user();
            $username = $user->username;

            $fromBankAccount = DB::table('keu_account')
                ->where('code', $request->from_bank_code)
                ->where('username', $username)
                ->first();

            if (!$fromBankAccount) {
                throw new \Exception('Bank sumber tidak ditemukan');
            }

            $toBankAccount = DB::table('keu_account')
                ->where('code', $request->to_bank_code)
                ->where('username', $username)
                ->first();

            if (!$toBankAccount) {
                throw new \Exception('Bank tujuan tidak ditemukan');
            }

            if ($fromBankAccount->balance < (int) $request->value) {
                throw new \Exception('Saldo bank sumber tidak mencukupi untuk transfer');
            }

            $today = date('Ymd');
            $lastTransaction = DB::table('keu_journal')
                ->where('username', $username)
                ->where('code', 'like', $today . '%')
                ->orderBy('code', 'desc')
                ->first();

            $sequence = '001';
            if ($lastTransaction) {
                $lastSequence = (int) substr($lastTransaction->code, -3);
                $sequence = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);
            }

            $transactionCode = $today . $sequence;

            $picturePath = '';
            if ($request->hasFile('picture')) {
                $file = $request->file('picture');
                $filename = 'kas_dan_bank/transfer/' . $username . '/' . $transactionCode . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();

                $uploaded = Storage::disk('gcs')->put($filename, file_get_contents($file->path()), [
                    'visibility' => 'public'
                ]);

                if ($uploaded) {
                    $picturePath = $filename;
                }
            }

            DB::table('keu_journal')->insert([
                'username' => $username,
                'subdomain' => '',
                'code' => $transactionCode,
                'user' => $user->name,
                'status' => 'debit',
                'account' => $request->to_bank_code,
                'name' => $toBankAccount->name,
                'description' => 'Transfer dari ' . $fromBankAccount->name . ' - ' . $request->description,
                'value' => (int) $request->value,
                'date_transaction' => Carbon::parse($request->date_transaction)->format('Y-m-d'),
                'date' => now(),
                'publish' => '1'
            ]);

            DB::table('keu_journal')->insert([
                'username' => $username,
                'subdomain' => '',
                'code' => $transactionCode,
                'user' => $user->name,
                'status' => 'credit',
                'account' => $request->from_bank_code,
                'name' => $fromBankAccount->name,
                'description' => 'Transfer ke ' . $toBankAccount->name . ' - ' . $request->description,
                'value' => (int) $request->value,
                'date_transaction' => Carbon::parse($request->date_transaction)->format('Y-m-d'),
                'date' => now(),
                'publish' => '1'
            ]);

            DB::table('keu_account')
                ->where('code', $request->from_bank_code)
                ->where('username', $username)
                ->decrement('balance', (int) $request->value);

            DB::table('keu_account')
                ->where('code', $request->to_bank_code)
                ->where('username', $username)
                ->increment('balance', (int) $request->value);

            DB::commit();

            DB::table('notifications')->insert([
                'username' => $username,
                'title' => 'Transfer Antar Bank',
                'message' => sprintf(
                    'Transfer dari %s ke %s sebesar %s berhasil dicatat pada %s. Kode transaksi: %s',
                    $fromBankAccount->name,
                    $toBankAccount->name,
                    'Rp ' . number_format($request->value, 0, ',', '.'),
                    Carbon::parse($request->date_transaction)->locale('id')->translatedFormat('d F Y'),
                    $transactionCode
                ),
                'is_read' => '0',
                'icon' => 'exchange_line',
                'priority' => $request->value >= 1000000 ? 'high' : 'normal',
                'date' => now(),
                'publish' => '1'
            ]);

            $pictureUrl = null;
            if ($picturePath) {
                $pictureUrl = GoogleCloudStorageHelper::getFileUrl($picturePath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Transfer antar bank berhasil',
                'data' => [
                    'transaction_code' => $transactionCode,
                    'from_bank_name' => $fromBankAccount->name,
                    'to_bank_name' => $toBankAccount->name,
                    'amount' => (int) $request->value,
                    'formatted_amount' => 'Rp ' . number_format($request->value, 0, ',', '.'),
                    'new_from_bank_balance' => DB::table('keu_account')
                        ->where('code', $request->from_bank_code)
                        ->where('username', $username)
                        ->value('balance'),
                    'new_to_bank_balance' => DB::table('keu_account')
                        ->where('code', $request->to_bank_code)
                        ->where('username', $username)
                        ->value('balance'),
                    'picture' => $pictureUrl,
                    'picture_path' => $picturePath
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Transfer failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getKasdanBankLaporan()
    {
        try {
            $username = auth()->user()->username;

            $accounts = AkunKeuanganModel::where('username', $username)
                ->where('code_account_category', '1')
                ->where('cash_and_bank', '1')
                ->where('publish', '1')
                ->orderBy('code')
                ->get();

            $totalSaldo = 0;
            $rincianSaldo = [];

            foreach ($accounts as $account) {
                $totalSaldo += $account->balance;
                $type = (strpos(strtolower($account->name), 'kas') !== false) ? 'Kas' : 'Bank';

                $rincianSaldo[] = [
                    'kode_akun' => $account->code,
                    'nama_akun' => $account->name,
                    'tipe' => $type,
                    'saldo' => number_format($account->balance, 0, ',', '.')
                ];
            }

            $riwayatTransaksi = DB::table('keu_journal as kj')
                ->select(
                    'kj.code',
                    'kj.date_transaction',
                    'kj.description',
                    'kj.value',
                    DB::raw('MAX(CASE WHEN kj.status = "debit" THEN ka.name END) as ke_rekening'),
                    DB::raw('MAX(CASE WHEN kj.status = "credit" THEN ka2.name END) as dari_rekening')
                )
                ->join('keu_account as ka', 'kj.account', '=', 'ka.code')
                ->join('keu_account as ka2', 'kj.account', '=', 'ka2.code')
                ->where('kj.username', $username)
                ->where('ka.cash_and_bank', '1')
                ->where('ka2.cash_and_bank', '1')
                ->where('kj.publish', '1')
                ->groupBy('kj.code', 'kj.date_transaction', 'kj.description', 'kj.value')
                ->orderBy('kj.date_transaction', 'desc')
                ->get();

            $laporanRiwayat = [];
            foreach ($riwayatTransaksi as $transaksi) {
                $laporanRiwayat[] = [
                    'kode_transaksi' => $transaksi->code,
                    'tanggal' => Carbon::parse($transaksi->date_transaction)->locale('id')->translatedFormat('d F Y'),
                    'dari_rekening' => $transaksi->dari_rekening ?: '',
                    'ke_rekening' => $transaksi->ke_rekening ?: '',
                    'nilai' => number_format($transaksi->value, 0, ',', '.'),
                    'keterangan' => $transaksi->description ?: ''
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_saldo' => number_format($totalSaldo, 0, ',', '.'),
                    'rincian_saldo' => $rincianSaldo,
                    'riwayat_transaksi' => $laporanRiwayat
                ],
                'message' => 'Laporan kas dan bank berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan kas dan bank: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getLaporanKasdanBankBulanan(Request $request)
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

            // Ambil data akun kas dan bank
            $accounts = DB::table('keu_account')
                ->where('username', $username)
                ->where('cash_and_bank', '1')
                ->where('publish', '1')
                ->orderBy('code')
                ->get();

            $totalSaldo = 0;
            $rincianSaldo = [];

            foreach ($accounts as $account) {
                $totalSaldo += $account->balance;
                $type = (strpos(strtolower($account->name), 'kas') !== false) ? 'Kas' : 'Bank';

                $rincianSaldo[] = [
                    'kode_akun' => $account->code,
                    'nama_akun' => $account->name,
                    'tipe' => $type,
                    'saldo' => 'Rp ' . number_format($account->balance, 0, ',', '.')
                ];
            }

            // Ambil riwayat transaksi kas dan bank dalam periode
            $riwayatTransaksi = DB::table('keu_journal as kj')
                ->select(
                    'kj.code',
                    'kj.date_transaction',
                    'kj.description',
                    'kj.value',
                    DB::raw('MAX(CASE WHEN kj.status = "debit" THEN ka.name END) as ke_rekening'),
                    DB::raw('MAX(CASE WHEN kj.status = "credit" THEN ka2.name END) as dari_rekening')
                )
                ->join('keu_account as ka', 'kj.account', '=', 'ka.code')
                ->join('keu_account as ka2', 'kj.account', '=', 'ka2.code')
                ->where('kj.username', $username)
                ->where('ka.cash_and_bank', '1')
                ->where('ka2.cash_and_bank', '1')
                ->whereBetween('kj.date_transaction', [$startOfMonth->format('Y-m-d'), $endOfMonth->format('Y-m-d')])
                ->where('kj.publish', '1')
                ->groupBy('kj.code', 'kj.date_transaction', 'kj.description', 'kj.value')
                ->orderBy('kj.date_transaction', 'desc')
                ->get();

            $laporanRiwayat = [];
            foreach ($riwayatTransaksi as $transaksi) {
                $laporanRiwayat[] = [
                    'kode_transaksi' => $transaksi->code,
                    'tanggal' => Carbon::parse($transaksi->date_transaction)->locale('id')->translatedFormat('d F Y'),
                    'dari_rekening' => $transaksi->dari_rekening ?: '',
                    'ke_rekening' => $transaksi->ke_rekening ?: '',
                    'nilai' => 'Rp ' . number_format($transaksi->value, 0, ',', '.'),
                    'keterangan' => $transaksi->description ?: ''
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'periode' => $startOfMonth->locale('id')->translatedFormat('F Y'),
                    'total_saldo' => 'Rp ' . number_format($totalSaldo, 0, ',', '.'),
                    'rincian_saldo' => $rincianSaldo,
                    'riwayat_transaksi' => $laporanRiwayat
                ],
                'message' => 'Laporan kas dan bank bulanan berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat laporan kas dan bank bulanan: ' . $e->getMessage()
            ], 500);
        }
    }

    private function determineTransactionType($debit, $credit)
    {
        if (!$debit || !$credit) {
            return 'unknown';
        }

        // Kas (101) ke Bank atau sebaliknya
        if (
            ($credit->account == '101' && $debit->account != '101') ||
            ($debit->account == '101' && $credit->account != '101')
        ) {
            return $credit->account == '101' ? 'setor' : 'tarik';
        }

        // Bank ke Bank
        if ($credit->account != '101' && $debit->account != '101') {
            return 'transfer';
        }

        return 'other';
    }
}