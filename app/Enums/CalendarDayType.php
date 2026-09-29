<?php

namespace App\Enums;

enum CalendarDayType: string
{
    case BusinessDay = 'business_day';
    case Saturday = 'saturday';
    case Sunday = 'sunday';
    case Holiday = 'holiday';
}
