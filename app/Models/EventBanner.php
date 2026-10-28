<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_id', 'content', 'secondary_content'])]
#[Hidden(['content', 'secondary_content'])]
class EventBanner extends Model {}
