<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'asset_code',
        'description',
        'category_id',
        'assign_to',
        'user_id',
        'dept_id',
        'voucher_no',
        'qty',
        'cost',
        'location',
        'purchase_date',
    ];

    /**
     * Get the category that owns the product.
     */
    public function category()
    {
        return $this->belongsTo('App\Models\Category');
    }
    /**
     * Get the category that owns the product.
     */
    public function user()
    {
        return $this->belongsTo('App\User');
    }
    /**
     * Get the category that owns the product.
     */
    public function department()
    {
        return $this->belongsTo('App\Models\Department', 'dept_id', 'id');
    }
}