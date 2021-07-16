<?php

namespace interactivid\Can\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class CanMigrationsCommand extends Command {

	protected $name = 'can:migration';

	protected $description = 'Create migrations for the Can package';
	
	public function handle()
    	{
        	$this->fire();
    	}

	public function fire()
	{
		$this->line('');
		$this->info('Attempting to create Can migration tables ...');

		try {
			$this->writeMigrationFiles();
		} catch(\Exception $e) {
			$this->error($e->getMessage());
		}

		$this->line('');
	}

	protected function params()
	{
//		$userModel = Config::get('auth.model');
//		$userPrimaryKey = (new $userModel())->getKeyName();

		return [
			'roleTable'          => Config::get('can.role_table'),
			'roleCustomTable'    => Config::get('can.role_custom_table'),
			'permissionTable'    => Config::get('can.permission_table'),
			'rolePermissionTable' => Config::get('can.role_permission_table'),
			'userRoleTable'       => Config::get('can.user_role_table'),
			'userPermissionTable' => Config::get('can.user_permission_table'),
//			'userModel' => $userModel,
//			'userPrimaryKey' => $userPrimaryKey
		];
	}

	protected function writeMigrationFiles()
	{
		$migrations = [
			'resources/migrations.php' => '_create_can_tables.php',
			'resources/migrations2.php' => '_add_group_ids_to_can_tables.php',
			'resources/migrations3.php' => '_create_roles_custom_table.php',
			'resources/migrations4.php' => '_change_primary_key_in_can_tables.php',
			'resources/migrations5.php' => '_add_group_id_to_role_permission_table.php',
			'resources/migrations6.php' => '_change_primary_key_in_roles_custom_table.php',
		];

		extract($this->params());

		$allFiles = scandir(base_path('/database/migrations'));

		foreach ($migrations as $template => $migrationFile)
		{
			foreach ($allFiles as $file)
			{
				if (strpos($file, $migrationFile) !== false)
				{
					// Migration already exists. Do not create.
					$this->info('Migration ' . $file . ' already exists. Skipped.');
					continue 2;
				}
			}

			$migrationFileWithDate = date('Y_m_d_His') . $migrationFile ;
			$this->info('Creating migration '  . $migrationFileWithDate . ' from ' . $template);

			$newFilePath = base_path('/database/migrations') . '/' . $migrationFileWithDate;
			$templatePath = substr(__DIR__, 0, -8) . $template;
			$output = include $templatePath;

			$file = fopen($newFilePath, 'x');
			fwrite($file, $output);
			fclose($file);

			$this->info('Migration created!');

			sleep(1);	// Allow migration to increment.
		}

	}
}
