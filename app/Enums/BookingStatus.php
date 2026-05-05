<?php

namespace App\Enums;

enum BookingStatus: string
{
    // 位置保留中，等待用戶付款；逾時（通常 15 分鐘）自動釋放位置
    case Reserved  = 'reserved';

    // 付款動作已發生，等待金流 webhook 回調確認
    // 所有付款方式都應經過此狀態，差別在等待時間：信用卡數秒，ATM/超商可能數小時至數天
    case Pending   = 'pending';

    // 金流確認收款，訂單成立
    case Confirmed = 'confirmed';

    // 逾時未付 or 主動取消（reserved / pending 均可流入）
    case Cancelled = 'cancelled';

    // 已 confirmed 後申請退款並完成
    case Refunded  = 'refunded';
}
