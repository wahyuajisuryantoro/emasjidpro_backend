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
        try {
            $username = auth()->user()->username;

            // Ambil semua akun kas dan bank
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
                    'formatted_balance' => 'Rp ' . number_format($account->balance, 0, ',', '.'),
                    'type' => $type
                ];
            });

            $totalBalance = $accounts->sum('balance');

            // Query yang sama dengan getAllDataKasdanBank tapi dengan limit 5
            $transactions = DB::table('keu_journal as kj1')
                ->select(
                    'kj1.code',
                    'kj1.date_transaction as date',
                    'kj1.description',
                    'kj1.value as amount',
                    'debit_acc.code as to_account_code',
                    'debit_acc.name as to_account_name',
                    'credit_acc.code as from_account_code',
                    'credit_acc.name as from_account_name'
                )
                ->join('keu_journal as kj2', function ($join) {
                    $join->on('kj1.code', '=', 'kj2.code')
                        ->where('kj1.status', '=', 'debit')
                        ->where('kj2.status', '=', 'credit');
                })
                ->join('keu_account as debit_acc', 'kj1.account', '=', 'debit_acc.code')
                ->join('keu_account as credit_acc', 'kj2.account', '=', 'credit_acc.code')
                ->where('kj1.username', $username)
                ->where('kj2.username', $username)
                ->where('debit_acc.username', $username)
                ->where('credit_acc.username', $username)
                ->where('debit_acc.cash_and_bank', '1')    
                ->where('credit_acc.cash_and_bank', '1')
                ->where('kj1.publish', '1')
                ->where('kj2.publish', '1')
                ->orderBy('kj1.date_transaction', 'desc')
                ->orderBy('kj1.code', 'desc')
                ->limit(5)
                ->get();

            $mappedTransactions = $transactions->map(function ($transaction) {
                $type = 'transfer'; 
                $fromAccountName = strtolower($transaction->from_account_name);
                $toAccountName = strtolower($transaction->to_account_name);

                if (strpos($fromAccountName, 'kas') !== false && strpos($toAccountName, 'bank') !== false) {
                    $type = 'setor'; // Kas ke Bank
                } elseif (strpos($fromAccountName, 'bank') !== false && strpos($toAccountName, 'kas') !== false) {
                    $type = 'tarik'; // Bank ke Kas
                } elseif (strpos($fromAccountName, 'bank') !== false && strpos($toAccountName, 'bank') !== false) {
                    $type = 'transfer'; // Bank ke Bank
                }

                return [
                    'id' => $transaction->code,
                    'code' => $transaction->code,
                    'fromAccount' => $transaction->from_account_name,
                    'toAccount' => $transaction->to_account_name,
                    'from_account' => [
                        'code' => $transaction->from_account_code,
                        'name' => $transaction->from_account_name
                    ],
                    'to_account' => [
                        'code' => $transaction->to_account_code,
                        'name' => $transaction->to_account_name
                    ],
                    'amount' => (double) $transaction->amount,
                    'formatted_amount' => 'Rp ' . number_format($transaction->amount, 0, ',', '.'),
                    'date' => $transaction->date,
                    'description' => $transaction->description,
                    'type' => $type,
                    'status' => 'completed'
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => $mappedAccounts,
                    'totalBalance' => $totalBalance,
                    'formatted_total_balance' => 'Rp ' . number_format($totalBalance, 0, ',', '.'),
                    'transactions' => $mappedTransactions
                ],
                'message' => 'Dashboard kas dan bank berhasil dimuat'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard kas dan bank: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getAllDataKasdanBank()
    {
        try {
            $username = auth()->user()->username;
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
            $totalBalance = $accounts->sum('balance');
            $kasAndBankCodes = $accounts->pluck('code')->toArray();
            $transactions = DB::table('keu_journal as kj1')
                ->select(
                    'kj1.code',
                    'kj1.date_transaction as date',
                    'kj1.description',
                    'kj1.value as amount',
                    'debit_acc.code as to_account_code',
                    'debit_acc.name as to_account_name',
                    'credit_acc.code as from_account_code',
                    'credit_acc.name as from_account_name'
                )
                ->join('keu_journal as kj2', function ($join) {
                    $join->on('kj1.code', '=', 'kj2.code')
                        ->where('kj1.status', '=', 'debit')
                        ->where('kj2.status', '=', 'credit');
                })
                ->join('keu_account as debit_acc', 'kj1.account', '=', 'debit_acc.code')
                ->join('keu_account as credit_acc', 'kj2.account', '=', 'credit_acc.code')
                ->where('kj1.username', $username)
                ->where('kj2.username', $username)
                ->where('debit_acc.username', $username)
                ->where('credit_acc.username', $username)
                ->where('debit_acc.cash_and_bank', '1')
                ->where('credit_acc.cash_and_bank', '1')
                ->where('kj1.publish', '1')
                ->where('kj2.publish', '1')
                ->orderBy('kj1.date_transaction', 'desc')
                ->orderBy('kj1.code', 'desc')
                ->get();
            $mappedTransactions = $transactions->map(function ($transaction) {
                $type = 'transfer';
                $fromAccountName = strtolower($transaction->from_account_name);
                $toAccountName = strtolower($transaction->to_account_name);

                if (strpos($fromAccountName, 'kas') !== false && strpos($toAccountName, 'bank') !== false) {
                    $type = 'setor';
                } elseif (strpos($fromAccountName, 'bank') !== false && strpos($toAccountName, 'kas') !== false) {
                    $type = 'tarik';
                } elseif (strpos($fromAccountName, 'bank') !== false && strpos($toAccountName, 'bank') !== false) {
                    $type = 'transfer';
                }

                return [
                    'code' => $transaction->code,
                    'date' => $transaction->date,
                    'description' => $transaction->description,
                    'amount' => (double) $transaction->amount,
                    'formatted_amount' => 'Rp ' . number_format($transaction->amount, 0, ',', '.'),
                    'from_account' => [
                        'code' => $transaction->from_account_code,
                        'name' => $transaction->from_account_name
                    ],
                    'to_account' => [
                        'code' => $transaction->to_account_code,
                        'name' => $transaction->to_account_name
                    ],
                    'type' => $type
                ];
            });
            $currentMonth = now()->month;
            $currentYear = now()->year;

            $monthlyStats = DB::table('keu_journal as kj1')
                ->join('keu_journal as kj2', function ($join) {
                    $join->on('kj1.code', '=', 'kj2.code')
                        ->where('kj1.status', '=', 'debit')
                        ->where('kj2.status', '=', 'credit');
                })
                ->join('keu_account as debit_acc', 'kj1.account', '=', 'debit_acc.code')
                ->join('keu_account as credit_acc', 'kj2.account', '=', 'credit_acc.code')
                ->where('kj1.username', $username)
                ->where('kj2.username', $username)
                ->where('debit_acc.username', $username)
                ->where('credit_acc.username', $username)
                ->where('debit_acc.cash_and_bank', '1')
                ->where('credit_acc.cash_and_bank', '1')
                ->where('kj1.publish', '1')
                ->where('kj2.publish', '1')
                ->whereMonth('kj1.date_transaction', $currentMonth)
                ->whereYear('kj1.date_transaction', $currentYear)
                ->selectRaw('
                COUNT(DISTINCT kj1.code) as total_transactions,
                SUM(kj1.value) as total_amount
            ')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'accounts' => $mappedAccounts,
                    'total_balance' => (double) $totalBalance,
                    'formatted_total_balance' => 'Rp ' . number_format($totalBalance, 0, ',', '.'),
                    'transactions' => $mappedTransactions,
                    'monthly_stats' => [
                        'month' => now()->locale('id')->translatedFormat('F Y'),
                        'total_transactions' => (int) $monthlyStats->total_transactions ?? 0,
                        'total_amount' => (double) $monthlyStats->total_amount ?? 0,
                        'formatted_total_amount' => 'Rp ' . number_format($monthlyStats->total_amount ?? 0, 0, ',', '.')
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

    private function determineTransactionType($debit, $credit, $kasCode = null)
    {
        $username = auth()->user()->username;

        // Ambil semua kode akun kas dan bank untuk user ini
        $kasAndBankCodes = DB::table('keu_account')
            ->where('username', $username)
            ->where('cash_and_bank', '1')
            ->where('publish', '1')
            ->pluck('code')
            ->toArray();

        // Jika kasCode tidak diberikan, cari otomatis
        if (!$kasCode) {
            $kasCode = DB::table('keu_account')
                ->where('username', $username)
                ->where('cash_and_bank', '1')
                ->where('publish', '1')
                ->whereRaw('LOWER(name) LIKE "%kas%"')
                ->value('code');
        }

        // Jika salah satu null, analisa yang ada
        if (!$debit && $credit) {
            // Hanya ada credit (uang keluar dari akun)
            if (!in_array($credit->account, $kasAndBankCodes)) {
                return 'other'; // Bukan transaksi kas/bank
            }

            if ($credit->account == $kasCode) {
                return 'kas_keluar'; // Uang keluar dari kas
            } else {
                return 'bank_keluar'; // Uang keluar dari bank
            }
        }

        if (!$credit && $debit) {
            // Hanya ada debit (uang masuk ke akun)
            if (!in_array($debit->account, $kasAndBankCodes)) {
                return 'other'; // Bukan transaksi kas/bank
            }

            if ($debit->account == $kasCode) {
                return 'kas_masuk'; // Uang masuk ke kas
            } else {
                return 'bank_masuk'; // Uang masuk ke bank
            }
        }

        // Jika kedua-duanya null
        if (!$debit || !$credit) {
            return 'unknown';
        }

        // Cek apakah keduanya termasuk akun kas dan bank
        $creditIsKasBank = in_array($credit->account, $kasAndBankCodes);
        $debitIsKasBank = in_array($debit->account, $kasAndBankCodes);

        // Jika salah satu bukan kas/bank, ini bukan transaksi kas/bank internal
        if (!$creditIsKasBank || !$debitIsKasBank) {
            // Tapi tetap cek apakah ini transaksi yang melibatkan kas/bank
            if ($creditIsKasBank) {
                return ($credit->account == $kasCode) ? 'kas_keluar' : 'bank_keluar';
            }
            if ($debitIsKasBank) {
                return ($debit->account == $kasCode) ? 'kas_masuk' : 'bank_masuk';
            }
            return 'other';
        }

        // Keduanya adalah akun kas/bank, tentukan jenis transfer

        // Transfer dari kas ke bank (setor)
        if ($credit->account == $kasCode && $debit->account != $kasCode) {
            return 'setor';
        }

        // Transfer dari bank ke kas (tarik)
        if ($debit->account == $kasCode && $credit->account != $kasCode) {
            return 'tarik';
        }

        // Transfer antar bank (bukan kas)
        if ($credit->account != $kasCode && $debit->account != $kasCode) {
            return 'transfer';
        }

        // Transfer internal kas (jarang terjadi)
        if ($credit->account == $kasCode && $debit->account == $kasCode) {
            return 'kas_internal';
        }

        return 'internal';
    }
}