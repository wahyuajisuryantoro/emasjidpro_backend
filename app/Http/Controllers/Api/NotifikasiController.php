<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotifikasiController extends Controller
{
    /**
     * Get notifications for authenticated user
     */
    public function getNotification(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $filter = $request->get('filter', 'all');
            $priority = $request->get('priority');
            $limit = $request->get('limit', 20);
            $page = $request->get('page', 1);
            $offset = ($page - 1) * $limit;


            $query = DB::table('notifications')
                ->where('username', $username)
                ->where('publish', '1');


            if ($filter === 'unread') {
                $query->where('is_read', '0');
            } elseif ($filter === 'read') {
                $query->where('is_read', '1');
            }


            if ($priority) {
                $query->where('priority', $priority);
            }


            $totalCount = $query->count();


            $notifications = $query
                ->orderBy('date', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $processedNotifications = $notifications->map(function ($notification) {
                return $this->processNotification($notification);
            });

            $unreadCount = DB::table('notifications')
                ->where('username', $username)
                ->where('publish', '1')
                ->where('is_read', '0')
                ->count();

            $totalNotifications = DB::table('notifications')
                ->where('username', $username)
                ->where('publish', '1')
                ->count();

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil diambil',
                'data' => [
                    'notifications' => $processedNotifications,
                    'pagination' => [
                        'current_page' => (int) $page,
                        'per_page' => (int) $limit,
                        'total' => $totalCount,
                        'last_page' => ceil($totalCount / $limit),
                        'has_more' => ($page * $limit) < $totalCount
                    ],
                    'counts' => [
                        'total' => $totalNotifications,
                        'unread' => $unreadCount,
                        'read' => $totalNotifications - $unreadCount
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil notifikasi: ' . $e->getMessage(),
                'error_detail' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function markAsRead(Request $request, $id)
    {
        try {
            $username = auth()->user()->username;

            $notification = DB::table('notifications')
                ->where('no', $id)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notifikasi tidak ditemukan'
                ], 404);
            }
            DB::table('notifications')
                ->where('no', $id)
                ->where('username', $username)
                ->update([
                    'is_read' => '1'
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil ditandai sebagai dibaca'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }


    public function markAllAsRead(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $updatedCount = DB::table('notifications')
                ->where('username', $username)
                ->where('is_read', '0')
                ->where('publish', '1')
                ->update([
                    'is_read' => '1'
                ]);

            return response()->json([
                'success' => true,
                'message' => "Berhasil menandai {$updatedCount} notifikasi sebagai dibaca",
                'data' => [
                    'updated_count' => $updatedCount
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai semua notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteNotification(Request $request, $id)
    {
        try {
            $username = auth()->user()->username;

            $notification = DB::table('notifications')
                ->where('no', $id)
                ->where('username', $username)
                ->where('publish', '1')
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notifikasi tidak ditemukan'
                ], 404);
            }

            DB::table('notifications')
                ->where('no', $id)
                ->where('username', $username)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Notifikasi berhasil dihapus'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getNotificationCounts(Request $request)
    {
        try {
            $username = auth()->user()->username;

            $counts = [
                'total' => DB::table('notifications')
                    ->where('username', $username)
                    ->where('publish', '1')
                    ->count(),
                'unread' => DB::table('notifications')
                    ->where('username', $username)
                    ->where('publish', '1')
                    ->where('is_read', '0')
                    ->count(),
                'urgent' => DB::table('notifications')
                    ->where('username', $username)
                    ->where('publish', '1')
                    ->where('priority', 'urgent')
                    ->count(),
                'high' => DB::table('notifications')
                    ->where('username', $username)
                    ->where('publish', '1')
                    ->where('priority', 'high')
                    ->count(),
                'normal' => DB::table('notifications')
                    ->where('username', $username)
                    ->where('publish', '1')
                    ->where('priority', 'normal')
                    ->count(),
                'low' => DB::table('notifications')
                    ->where('username', $username)
                    ->where('publish', '1')
                    ->where('priority', 'low')
                    ->count()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Counts berhasil diambil',
                'data' => $counts
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil counts: ' . $e->getMessage()
            ], 500);
        }
    }

    private function processNotification($notification)
    {
        $messageData = null;
        $displayMessage = $notification->message;
        $type = 'general';

        if ($this->isJson($notification->message)) {
            $messageData = json_decode($notification->message, true);
            $displayMessage = $messageData['display_message'] ?? $notification->message;
            $type = $messageData['type'] ?? 'general';
        }

        $date = Carbon::parse($notification->date);
        $formattedDate = $this->formatNotificationDate($date);

        return [
            'id' => $notification->no,
            'title' => $notification->title,
            'message' => $displayMessage,
            'message_data' => $messageData,
            'icon' => $notification->icon ?? 'ri-notification-line',
            'priority' => $notification->priority ?? 'normal',
            'is_read' => $notification->is_read === '1',
            'date' => $notification->date,
            'formatted_date' => $formattedDate,
            'type' => $type
        ];
    }

    private function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function formatNotificationDate(Carbon $date)
    {
        $now = Carbon::now();
        $diffInMinutes = $now->diffInMinutes($date);

        if ($diffInMinutes < 1) {
            return 'Baru saja';
        } elseif ($diffInMinutes < 60) {
            return $diffInMinutes . ' menit yang lalu';
        } elseif ($diffInMinutes < 1440) {
            $hours = floor($diffInMinutes / 60);
            return $hours . ' jam yang lalu';
        } elseif ($diffInMinutes < 10080) {
            $days = floor($diffInMinutes / 1440);
            return $days . ' hari yang lalu';
        } else {
            return $date->locale('id')->translatedFormat('d F Y');
        }
    }
}