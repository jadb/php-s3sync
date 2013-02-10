# PHP S3 Sync

## Install

For now, and though if can be done without, it's only explained how to install it using [Composer][1].

Start by adding `jadb/s3sync` to your `composer.json` dependencies like so:

	{
		"require": {
			"jadb/s3sync": "*"
		}
	}

Now update your dependencies:

	php composer.phar update

## Setup

Setting it up is just about copying and customizing a file:

	cp vendor/amazonwebservices/aws-sdk-for-php/config-sample.inc.php aws.conf
	vi aws.conf


## Usage

To sync a directory `relative/path/to/sync` with the `backups` S3 bucket:

	./vendor/jadb/s3sync/src/s3sync backups relative/path/to/sync dry verbose

The shell is based on `JadB\S3Sync`, check it out and build your own scripts.

[1]:http://getcomposer.org
