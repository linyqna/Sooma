<?php

declare(strict_types=1);

function syncUserBookingStatuses(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $pdo->exec("set search_path to sooma_wellness, public");

    $expireByPaymentDeadline = $pdo->prepare("
        update sooma_wellness.bookings b
           set booking_status = 'expired',
               updated_at = clock_timestamp()
          from sooma_wellness.customers c
         where b.customer_id = c.customer_id
           and c.user_id = :user_id
           and b.booking_status = 'waiting_payment'
           and b.expires_at is not null
           and b.expires_at <= clock_timestamp()
    ");
    $expireByPaymentDeadline->execute([':user_id' => $userId]);

    $expirePastStartTime = $pdo->prepare("
        update sooma_wellness.bookings b
           set booking_status = 'expired',
               updated_at = clock_timestamp()
          from sooma_wellness.customers c,
               sooma_wellness.class_schedules cs
         where b.customer_id = c.customer_id
           and b.schedule_id = cs.schedule_id
           and c.user_id = :user_id
           and b.booking_status in ('waiting_verification', 'waiting_payment', 'pending')
           and ((cs.schedule_date + cs.start_time) at time zone 'Asia/Jakarta') <= clock_timestamp()
    ");
    $expirePastStartTime->execute([':user_id' => $userId]);

    $completePastSchedule = $pdo->prepare("
        update sooma_wellness.bookings b
           set booking_status = 'completed',
               updated_at = clock_timestamp()
          from sooma_wellness.customers c,
               sooma_wellness.class_schedules cs
         where b.customer_id = c.customer_id
           and b.schedule_id = cs.schedule_id
           and c.user_id = :user_id
           and b.booking_status = 'confirmed'
           and ((cs.schedule_date + cs.start_time) at time zone 'Asia/Jakarta') + interval '1 hour' <= clock_timestamp()
    ");
    $completePastSchedule->execute([':user_id' => $userId]);
}