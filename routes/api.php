<?php

use App\Http\Controllers\Api\AkunKeuanganController;
use App\Http\Controllers\Api\LaporanPendapatanBebanController;
use App\Http\Controllers\Api\NeracaSaldoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AsetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\HutangController;
use App\Http\Controllers\Api\PiutangController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\BukuBesarController;
use App\Http\Controllers\Api\JurnalUmumController;
use App\Http\Controllers\Api\KasdanBankController;
use App\Http\Controllers\Api\NotifikasiController;
use App\Http\Controllers\Api\PendapatanController;
use App\Http\Controllers\Api\PengeluaranController;


Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/data-masjid', [HomeController::class, 'getDataMasjid']);
    Route::get('/home-data', [HomeController::class, 'getHomeData']);
    Route::get('/get-profile', [ProfileController::class, 'getProfile']);
    Route::post('/update-picture', [ProfileController::class, 'updatePictureProfile']);
    Route::post('/update-profile', [ProfileController::class, 'updateProfile']);
    Route::post('/update-password', [ProfileController::class, 'updatePassword']);

    // Route Pendapatan
    Route::get('/dashboard-pendapatan', [PendapatanController::class, 'getDashboardPendapatan']);
    Route::get('/riwayat-pendapatan', [PendapatanController::class, 'getRiwayatPendapatan']);
    Route::post('/transaksi-pendapatan', [PendapatanController::class, 'storeTransaksiPendapatan']);
    Route::get('/accounts-pendapatan', [PendapatanController::class, 'getAccountsForPendapatan']);
    Route::get('/laporan-pendapatan-harian', [PendapatanController::class, 'getLaporanPendapatanHarian']);
    Route::get('/laporan-tujuh-hari-terakhir', [PendapatanController::class, 'getLaporanPendapatan7HariTerakhir']);
    Route::post('/laporan-pendapatan-bulanan', [PendapatanController::class, 'getLaporanPendapatanBulanan']);
    Route::post('/laporan-pendapatan-custom', [PendapatanController::class, 'getLaporanPendapatanCustom']);

    // Route Pengeluaran
    Route::get('/dashboard-pengeluaran', [PengeluaranController::class, 'getDashboardPengeluaran']);
    Route::get('/riwayat-pengeluaran', [PengeluaranController::class, 'getRiwayatPengeluaran']);
    Route::post('/transaksi-pengeluaran', [PengeluaranController::class, 'storeTransaksiPengeluaran']);
    Route::get('/accounts-pengeluaran', [PengeluaranController::class, 'getAccountsForPengeluaran']);
    Route::get('/laporan-pengeluaran-harian', [PengeluaranController::class, 'getLaporanPengeluaranHarian']);
    Route::get('/laporan-tujuh-hari-pengeluaran', [PengeluaranController::class, 'getLaporanPengeluaran7HariTerakhir']);
    Route::post('/laporan-pengeluaran-bulanan', [PengeluaranController::class, 'getLaporanPengeluaranBulanan']);
    Route::post('/laporan-pengeluaran-custom', [PengeluaranController::class, 'getLaporanPengeluaranCustom']);

    // Route Hutang
    Route::get('/dashboard-hutang', [HutangController::class, 'getDashboardHutang']);
    Route::get('/accounts-hutang', [HutangController::class, 'getAccountsForHutang']);
    Route::get('/accounts-cicilan', [HutangController::class, 'getAccountsForTambahCicilan']);
    Route::get('/daftar-hutang', [HutangController::class, 'getDaftarHutang']);
    Route::post('/tambah-hutang', [HutangController::class, 'tambahHutang']);
    Route::post('/tambah-cicilan', [HutangController::class, 'tambahCicilan']);
    Route::get('/laporan-hutang', [HutangController::class, 'getLaporanHutang']);
    Route::get('/detail-hutang/{id}', [HutangController::class, 'getDetailHutang']);

    // Route Piutang
    Route::get('/dashboard-piutang', [PiutangController::class, 'getDashboardPiutang']);
    Route::get('/accounts-piutang', [PiutangController::class, 'getAccountsForPiutang']);
    Route::get('/accounts-cicilan-piutang', [PiutangController::class, 'getAccountsForTambahCicilanPiutang']);
    Route::get('/daftar-piutang', [PiutangController::class, 'getDaftarPiutang']);
    Route::post('/tambah-piutang', [PiutangController::class, 'tambahPiutang']);
    Route::post('/tambah-cicilan-piutang', [PiutangController::class, 'tambahCicilanPiutang']);
    Route::get('/laporan-piutang', [PiutangController::class, 'getLaporanPiutang']);
    Route::get('/detail-piutang/{id}', [PiutangController::class, 'getDetailPiutang']);

    // Route Jurnal Umum
    Route::get('/jurnal-umum', [JurnalUmumController::class, 'getJurnalUmum']);
    Route::get('/jurnal-umum/{code}', [JurnalUmumController::class, 'getDetailJurnal']);

    // Route Laporan Pendapatan dan Beban
    Route::get('/pendapatan-beban', [LaporanPendapatanBebanController::class, 'getLaporanPendapatanBeban']);
    // Route Buku Besar
    Route::get('/buku-besar/account', [BukuBesarController::class, 'getAccountBukuBesar']);
    Route::get('/buku-besar/{code_account}/detail', [BukuBesarController::class, 'getBukuBesarByAccount']);

    // Route Neraca Saldo
    Route::get('/neraca-saldo', [NeracaSaldoController::class, 'getNeracaSaldo']);

    // Route Kas dan Bank
    Route::get('/dashboard-kasdanbank', [KasdanBankController::class, 'getDashboardKasdanBank']);
    Route::get('/all-kasdanbank', [KasdanBankController::class, 'getAllDataKasdanBank']);
    Route::get('/accounts-kasdanbank', [KasdanBankController::class, 'getAccountsForKasdanBank']);
    Route::get('/bank-accounts-transfer', [KasdanBankController::class, 'getBankAccountsForTransfer']);
    Route::post('/laporan-kasdanbank', [KasdanBankController::class, 'getLaporanKasdanBankBulanan']);
    Route::post('/setor-kasdanbank', [KasdanBankController::class, 'setorKasdanBank']);
    Route::post('/tarik-kasdanbank', [KasdanBankController::class, 'tarikKasDanBank']);
    Route::post('/transfer-kasdanbank', [KasdanBankController::class, 'transferKasdanBank']);
    // Route Aset
    Route::get('/dashboard-aset', [AsetController::class, 'getDashboardAset']);
    Route::get('/aset-all', [AsetController::class, 'getAllAset']);
    Route::get('/aset-list', [AsetController::class, 'getAsetList']);
    Route::get('/aset/categories', [AsetController::class, 'getAssetCategories']);
    Route::get('/aset/payment-accounts', [AsetController::class, 'getPaymentAccounts']);
    Route::get('/aset/accounts', [AsetController::class, 'getAssetAccounts']);
    Route::get('/aset/sold', [AsetController::class, 'getAllDataSellAset']);
    Route::post('/aset/buy', [AsetController::class, 'buyAset']);
    Route::post('/aset/add-categories', [AsetController::class, 'addCategoryAset']);
    Route::post('/aset/depreciation/add', [AsetController::class, 'addAssetDepreciation']);
    Route::get('/aset/categories/{no}', [AsetController::class, 'getCategoryAset']);
    Route::put('/aset/update-categories/{no}', [AsetController::class, 'updateCategoryAset']);
    Route::post('/aset-sell/{no}', [AsetController::class, 'sellAset']);
    Route::get('/aset-detail/{no}', [AsetController::class, 'getDetailAset']);
    Route::post('/aset/{no}/update', [AsetController::class, 'updateAset']);
    Route::delete('/aset/delete-categories/{no}', [AsetController::class, 'deleteCategoryAset']);
    Route::get('assets/{no}/depreciation', [AsetController::class, 'getAssetDepreciation']);
    Route::post('assets/depreciation/{no}/edit', [AsetController::class, 'editAssetDepreciation']);
    Route::delete('assets/depreciation/{no}/delete', [AsetController::class, 'deleteAssetDepreciation']);
    Route::post('aset/{no}/upload-picture', [AsetController::class, 'uploadAssetPicture']);
    Route::post('aset/{no}/update-picture', [AsetController::class, 'updateAssetPicture']);
    Route::delete('aset/{no}/delete-picture', [AsetController::class, 'deleteAssetPicture']);
    Route::post('aset/{no}/upload-document', [AsetController::class, 'uploadAssetDocument']);
    Route::post('aset/{no}/update-document/{documentIndex}', [AsetController::class, 'updateAssetDocument']);
    Route::delete('aset/{no}/delete-document/{documentIndex}', [AsetController::class, 'deleteAssetDocument']);
    Route::get('aset/{no}/documents', [AsetController::class, 'getAssetDocuments']);

    Route::get('/akun-keuangan', [AkunKeuanganController::class, 'getAccountsKeuangan']);
    Route::get('/akun-keuangan/categories', [AkunKeuanganController::class, 'getAccountCategories']);
    Route::post('/akun-keuangan', [AkunKeuanganController::class, 'storeAccount']);


    Route::get('/notifications', [NotifikasiController::class, 'getNotification']);
    Route::post('/notifications/read-all', [NotifikasiController::class, 'markAllAsRead']);
    Route::get('/notifications/counts', [NotifikasiController::class, 'getNotificationCounts']);
    Route::post('/notifications/{id}/delete', [NotifikasiController::class, 'deleteNotification']);
    Route::post('/notifications/{id}/read', [NotifikasiController::class, 'markAsRead']);

    Route::get('/setting', [SettingController::class, 'getDataSetting']);
    Route::get('/setting-masjid', [SettingController::class, 'getDataMasjidAndSetting']);
    Route::post('/update-masjid', [SettingController::class, 'updateDataMasjid']);
    Route::post('/update-setting', [SettingController::class, 'updateSetting']);
});
