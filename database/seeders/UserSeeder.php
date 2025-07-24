<?php

namespace Database\Seeders;

use Illuminate\Support\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        DB::table('user')->insert([
            'no' => 1,
            'username' => 'maskhoerul@gmail.com',
            'password' => md5('123456'),
            'category' => 'member',
            'replika' => 'umam',
            'referral' => '',
            'name' => 'Khoerul Umam ST',
            'subdomain' => '',
            'link' => 'khoerul-umam',
            'number_id' => '123456789',
            'birth' => 'Semarang, 20 Januari 1980',
            'sex' => 'L',
            'address' => 'Jalan Apa saja temanggung',
            'city' => 'Temanggung',
            'phone' => '085740000146',
            'email' => 'maskhoerul@gmail.com',
            'bank_name' => '',
            'bank_branch' => '',
            'bank_account_number' => '',
            'bank_account_name' => '',
            'last_login' => Carbon::parse('2025-03-28 14:32:37'),
            'last_ipaddress' => '::1',
            'picture' => '',
            'date' => Carbon::parse('2018-03-18 00:00:00'),
            'publish' => '1',
        ]);
    }
}
