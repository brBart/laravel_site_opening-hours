<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DefaultEvent extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'rrule', 'start_date', 'end_date', 'calendar_id', 'label',
    ];

    /**
     * Parent Object Calendar
     *
     * @return Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function calendar()
    {
        return $this->belongsTo('App\Models\DefaultCalendar', 'calendar_id');
    }
}
