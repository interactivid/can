<?php

namespace interactivid\Can;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoleCustom extends Role {
	use RolesAndPermissionsHelper;

	protected static $table = 'roles_custom';
}