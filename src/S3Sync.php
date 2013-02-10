<?php

namespace JadB {

	require __DIR__ . '/../vendor/autoload.php';
	require __DIR__ . '/../aws.conf';

	class S3Sync {

		const MAX_FILE_SIZE_IN_BYTES = 4294967296;

		public $S3;

		private $__bucket = '';
		private $__buckets = array();
		private $__deleted = 0;
		private $__directory = '';
		private $__dry = false;
		private $__errored = 0;
		private $__errorsMsg = array();
		private $__existing = 0;
		private $__fileHashes = array();
		private $__files = array();
		private $__s3Objects = array();
		private $__start;
		private $__stucked = 0;
		private $__uploaded = 0;
		private $__verbose = false;

		public function __construct($bucket, $directory, $dry = false, $verbose = true) {
			$this->__start = microtime(true);

			foreach (compact('bucket', 'directory', 'dry', 'verbose') as $key => $val) {
				if (in_array($key, array('bucket', 'directory')) && empty($val)) {
					throw new \Exception("Missing $key value.");
				}
				$key = "__$key";
				$this->$key = $val;
			}

			$this->__out("Initiated sync service with bucket '$bucket'.");
			if ($this->__dry) {
				$this->__out("WARNING: YOUR ARE RUNNING IN DRY MODE, NO FILES WILL BE UPLOADED TO S3.");
			}

			$this->S3 = new \AmazonS3();
			$this->S3->disable_ssl_verification(false);

			$this->isValidBucket();

			$this->__files = $this->listFiles($directory);
			if (false === $this->__files) {
				throw new \Exception("Unable to list files in '$directory'.");
			}

			$this->__out(sprintf("Found %s files to process.\n", count($this->__files)));
		}

		public function __destruct() {
			$out = array();
			$out[] = "\n\n************************* RESULTS ***********************";
			$out[] = sprintf("Total time: %ss", microtime(true) - $this->__start);
			$out[] = "Total files checked: " . count($this->__files);
			$out[] = "Total files uploaded to S3: {$this->__uploaded}";
			$out[] = "Total files deleted from S3: {$this->__deleted}";
			$out[] = "Total files already on S3: {$this->__existing}";
			$out[] = "Total failed uploads to S3: " . $this->__errored;
			$out[] = "Total failed deletes from S3: " . $this->__stucked;
			$out[] = "*********************************************************";

			if (count($this->__errorsMsg)) {
				$out[] = implode("\n", $this->__errorsMsg);
			}

			$this->__out(implode("\n", $out));
		}

		public function delete($hash) {
			$msg = "[%s] $hash";
			if ($this->__dry) {
				$this->__out(sprintf($msg, "D"));
				$this->__deleted++;
				return;
			}

			$Response = $this->S3->delete_object($this->__bucket, $hash);

			if (!$Response->isOK()) {
				$this->__errorsMsg[] = "Could not delete the '$hash' file from the S3 bucket.";
				$this->__out(sprintf($msg, "E"));
				$this->__stucked++;
				return;
			}

			$this->__out(sprintf($msg, "D"));
			$this->__deleted++;
		}

		public function isValidBucket() {
			$this->__buckets = $this->S3->get_bucket_list();
			if (!in_array($this->__bucket, $this->__buckets)) {
				throw new \Exception("Invalid bucket name '{$this->__bucket}'.");
			}
		}

		public function listFiles($dir) {
			$files = array();

			if (substr($dir, -1) != '/') {
				$dir .= '/';
			}

			$dh = dir($dir);

			if (!is_a($dh, 'Directory')) {
				throw new \Exception("Unable to open directory '$dir' to list its files.");
			}

			while (false !== ($file = $dh->read())) {
				// Skip hidden files;
				if ('.' === $file[0]) {
					continue;
				}

				$path = "$dir$file";
				if (is_dir($path)) {
					if  (is_readable($path)) {
						$files = array_merge($files, $this->listFiles($path));
					}
				} else if (is_readable($path)) {
					$hash = $path;
					$size = filesize($path);

					if ($size > self::MAX_FILE_SIZE_IN_BYTES) {
						$this->__errorsMsg[] = "The file '$path' will not be processed as it exceeds the max size.";
						continue;
					}

					$files[$hash] = compact('path', 'file', 'hash');
					if (in_array($hash, $this->__fileHashes)) {
						$this->__errorsMsg[] = "The file '$path' will not be processed as it is a duplicate.";
						continue;
					}

					$this->__fileHashes[] = $hash;
				}
			}

			$dh->close();

			return $files;
		}

		public function sync() {
			$msg = "[S] %s";
			$objects = $this->S3->get_object_list($this->__bucket);

			if (!empty($this->__files)) {
				$this->__out("Uploading:");
				foreach ($this->__files as $hash => $file) {
					if (in_array($hash, $objects)) {
						$this->__out(sprintf($msg, $hash));
						$this->__existing++;
						continue;
					}
					$this->upload($file);
				}
			}

			$this->__out();

			if (!empty($objects)) {
				$this->__out("Deleting:");
				foreach ($objects as $hash) {
					if (!isset($this->__files[$hash])) {
						$this->delete($hash);
						continue;
					}
					$this->__out(sprintf($msg, $hash));
				}
			}
		}

		public function upload($file) {
			extract($file);
			$msg = "[%s] $hash";

			$options = array(
				'fileUpload' => $path,
				'storage' => \AmazonS3::STORAGE_REDUCED,
				'meta' => compact('path', 'hash')
			);

			if ($this->__dry) {
				$this->__out(sprintf($msg, "U"));
				$this->__uploaded++;
				return;
			}

			$Response = $this->S3->create_object($this->__bucket, $hash, $options);

			if (!$Response->isOK()) {
				$this->__errorsMsg = "Failed to upload file '$hash' to S3 bucket.";
				$this->__out(sprintf($msg, "E"));
				$this->__errored++;
				return;
			}

			$this->__out(sprintf($msg, "U"));
			$this->__uploaded++;
		}

		private function __out($msg = null, $nl = true) {
			if (!$this->__verbose) {
				return;
			}
			echo $msg;
			if ($nl) {
				echo "\n";
			}
		}

	}

}
