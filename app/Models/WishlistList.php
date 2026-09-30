<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WishlistList extends Model {
    protected $fillable = ['user_id','name'];
    protected $table = 'wishlist_lists';
}
