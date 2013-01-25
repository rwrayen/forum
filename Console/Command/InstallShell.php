<?php
/**
 * Forum - InstallShell
 *
 * @author      Miles Johnson - http://milesj.me
 * @copyright   Copyright 2006-2011, Miles Johnson, Inc.
 * @license     http://opensource.org/licenses/mit-license.php - Licensed under The MIT License
 * @link        http://milesj.me/code/cakephp/forum
 */

Configure::write('debug', 2);
Configure::write('Cache.disable', true);

App::uses('ConnectionManager', 'Model');
App::uses('Security', 'Utility');
App::uses('Sanitize', 'Utility');
App::uses('Validation', 'Utility');

config('database');

class InstallShell extends Shell {

	/**
	 * Installer configuration.
	 *
	 * @var array
	 */
	public $install = array(
		'table' => 'users',
		'user_id' => '',
		'username' => '',
		'password' => '',
		'email' => ''
	);

	/**
	 * DB Instance.
	 *
	 * @var DataSource
	 */
	public $db;

	/**
	 * Execute installer!
	 *
	 * @return void
	 */
	public function main() {
		$this->out();
		$this->out('Plugin: Forum');
		$this->out('Version: ' . Configure::read('Forum.version'));
		$this->out('Copyright: Miles Johnson, 2010-' . date('Y'));
		$this->out('Help: http://milesj.me/code/cakephp/forum');
		$this->out('Shell: Installer');
		$this->out();
		$this->out('This shell installs the forum plugin by creating the required database tables,');
		$this->out('setting up the admin user, applying necessary table prefixes, and more.');

		$this->hr(1);
		$this->out('Installation Steps:');
		$this->out();
		$this->steps(1);

		if ($this->usersTable()) {
			$this->steps(2);

			if ($this->checkStatus()) {
				$this->steps(3);

				if ($this->createTables()) {
					$this->steps(4);

					if ($this->setupAdmin()) {
						$this->steps(5);
						$this->finalize();
					}
				}
			}
		}
	}

	/**
	 * Table of contents.
	 *
	 * @param int $state
	 * @return void
	 */
	public function steps($state = 0) {
		$this->hr(1);

		$steps = array(
			'Users Table',
			'Check Installation Status',
			'Create Database Tables',
			'Create Administrator',
			'Finalize Installation'
		);

		foreach ($steps as $i => $step) {
			$index = ($i + 1);

			$this->out('[' . (($index < $state) ? 'x' : $index) . '] ' . $step);
		}

		$this->out();
	}

	/**
	 * Grab the users table.
	 *
	 * @return boolean
	 */
	public function usersTable() {
		$table = $this->in('What is the name of your users table?');

		if (!$table) {
			$this->out('Please provide a users table.');

			return $this->usersTable();

		} else {
			$table = trim($table);
			$this->out(sprintf('You have chosen the table: %s', $table));
		}

		$answer = strtoupper($this->in('Is this correct?', array('Y', 'N')));

		if ($answer === 'Y') {
			$this->install['table'] = $table;
		} else {
			return $this->usersTable();
		}

		return true;
	}

	/**
	 * Check the database status before installation.
	 *
	 * @return boolean
	 */
	public function checkStatus() {
		$this->db = ConnectionManager::getDataSource(FORUM_DATABASE);

		// Check connection
		if (!$this->db->isConnected()) {
			$this->out(sprintf('Error: Database connection for %s failed!', FORUM_DATABASE));

			return false;
		}

		// Check the users tables
		$tables = $this->db->listSources();

		if (!in_array($this->install['table'], $tables)) {
			$this->out(sprintf('Error: No %s table was found in %s.', $this->install['table'], FORUM_DATABASE));

			return false;
		}

		$this->out('Installation status good, proceeding...');

		return true;
	}

	/**
	 * Create the database tables based off the schemas.
	 *
	 * @return boolean
	 */
	public function createTables() {
		$schemas = glob(FORUM_PLUGIN . 'Config/Schema/*.sql');
		$executed = 0;
		$total = count($schemas);
		$tables = array();

		foreach ($schemas as $schema) {
			$contents = file_get_contents($schema);
			$contents = String::insert($contents, array('prefix' => FORUM_PREFIX), array('before' => '{', 'after' => '}'));
			$contents = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $contents);

			$queries = explode(';', $contents);
			$tables[] = FORUM_PREFIX . str_replace('.sql', '', basename($schema));

			foreach ($queries as $query) {
				$query = trim($query);

				if ($query !== '' && $this->db->execute($query)) {
					$command = trim(substr($query, 0, 6));

					if ($command === 'CREATE' || $command === 'ALTER') {
						$executed++;
					}
				}
			}
		}

		if ($executed != $total) {
			$this->out('Error: Failed to create database tables!');
			$this->out('Rolling back and dropping any created tables.');

			foreach ($tables as $table) {
				$this->db->execute(sprintf('DROP TABLE `%s`;', $table));
			}

			return false;
		} else {
			$this->out('Tables created successfully...');
		}

		return true;
	}

	/**
	 * Setup the admin user.
	 *
	 * @return boolean
	 */
	public function setupAdmin() {
		$answer = strtoupper($this->in('Would you like to [c]reate a new user, or use an [e]xisting user?', array('C', 'E')));
		$userMap = Configure::read('Forum.userMap');
		$statusMap = Configure::read('Forum.statusMap');

		// New User
		if ($answer === 'C') {
			$this->install['username'] = $this->_newUser('username');
			$this->install['password'] = $this->_newUser('password');
			$this->install['email'] = $this->_newUser('email');

			$result = $this->db->execute(sprintf("INSERT INTO `%s` (`%s`, `%s`, `%s`, `%s`) VALUES (%s, %s, %s, %s);",
				$this->install['table'],
				$userMap['username'],
				$userMap['password'],
				$userMap['email'],
				$userMap['status'],
				$this->db->value(Sanitize::clean($this->install['username'])),
				$this->db->value(Security::hash($this->install['password'], null, true)),
				$this->db->value($this->install['email']),
				$this->db->value($statusMap['active'])
			));

			if ($result) {
				$this->install['user_id'] = $this->db->lastInsertId();
			} else {
				$this->out('An error has occured while creating the user.');

				return $this->setupAdmin();
			}

		// Old User
		} else if ($answer === 'E') {
			$this->install['user_id'] = $this->_oldUser();

		// Redo
		} else {
			return $this->setupAdmin();
		}

		$result = $this->db->execute(sprintf("INSERT INTO `%saccess` (`access_level_id`, `user_id`, `created`) VALUES (4, %d, NOW());",
			FORUM_PREFIX,
			$this->install['user_id']
		));

		if (!$result) {
			$this->out('An error occured while granting administrator access.');

			return $this->setupAdmin();
		}

		return true;
	}

	/**
	 * Finalize the installation, woop woop.
	 *
	 * @return void
	 */
	public function finalize() {
		$this->hr(1);
		$this->out('Forum installation complete! Your admin credentials:');
		$this->out();
		$this->out(sprintf('Username: %s', $this->install['username']));
		$this->out(sprintf('Email: %s', $this->install['email']));
		$this->out();
		$this->out('Please read the documentation for further configuration instructions.');
		$this->hr(1);
	}

	/**
	 * Gather all the data for creating a new user.
	 *
	 * @param string $mode
	 * @return string
	 */
	protected function _newUser($mode) {
		$userMap = Configure::read('Forum.userMap');

		switch ($mode) {
			case 'username':
				$username = trim($this->in('Username:'));

				if (!$username) {
					$username = $this->_newUser($mode);
				} else {
					$result = $this->db->fetchRow(sprintf("SELECT COUNT(*) AS `count` FROM `%s` AS `User` WHERE `%s` = %s",
						$this->install['table'],
						$userMap['username'],
						$this->db->value($username)
					));

					if ($this->db->hasResult() && $result[0]['count']) {
						$this->out('Username already exists, please try again.');
						$username = $this->_newUser($mode);
					}
				}

				return $username;
			break;

			case 'password':
				$password = trim($this->in('Password:'));

				if (!$password) {
					$password = $this->_newUser($mode);
				}

				return $password;
			break;

			case 'email':
				$email = trim($this->in('Email:'));

				if (!$email) {
					$email = $this->_newUser($mode);

				} else if (!Validation::email($email)) {
					$this->out('Invalid email address, please try again.');
					$email = $this->_newUser($mode);

				} else {
					$result = $this->db->fetchRow(sprintf("SELECT COUNT(*) AS `count` FROM `%s` AS `User` WHERE `%s` = %s",
						$this->install['table'],
						$userMap['email'],
						$this->db->value($email)
					));

					if ($this->db->hasResult() && $result[0]['count']) {
						$this->out('Email already exists, please try again.');
						$email = $this->_newUser($mode);
					}
				}

				return $email;
			break;
		}

		return null;
	}

	/**
	 * Use an old user as an admin.
	 *
	 * @return string
	 */
	protected function _oldUser() {
		$user_id = trim($this->in('User ID:'));
		$userMap = Configure::read('Forum.userMap');

		if (!$user_id || !is_numeric($user_id)) {
			$user_id = $this->_oldUser();

		} else {
			$result = $this->db->fetchRow(sprintf("SELECT * FROM `%s` AS `User` WHERE `id` = %d LIMIT 1",
				$this->install['table'],
				$user_id
			));

			if (!$result) {
				$this->out('User ID does not exist, please try again.');
				$user_id = $this->_oldUser();

			} else {
				$this->install['username'] = $result['User'][$userMap['username']];
				$this->install['password'] = $result['User'][$userMap['password']];
				$this->install['email'] = $result['User'][$userMap['email']];
			}
		}

		return $user_id;
	}

}