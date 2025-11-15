# Fluid Rename

This TYPO3 extension provides a console command that automatically detects
potential Fluid template files in another installed TYPO3 extension and
renames them to the new Fluid file extension `*.fluid.*`. Note that this
file extension only works on TYPO3 >= 14.0, so if you need to support older
versions, just stick to the old generic file extensions.

**Warning: Use this package at your own risk! The command renames actual
files in your TYPO3 project, so you should know what you're doing!**

## Installation

This package is provided only for composer-based TYPO3 setups:

```sh
composer req --dev praetorius/fluid-rename
```

## Usage

```sh
# Usage with composer name:
vendor/bin/typo3 fluid:rename:templates my-vendor/my-package-name

# Usage with extension key:
vendor/bin/typo3 fluid:rename:templates my_extension
```

The command is a two-step process: First, templates are processed that contain
clear Fluid template markers, such as a ViewHelper call or a namespace import.
Then, additional files are listed that based on their file extension might also
be Fluid templates.

Both steps need to be confirmed by the user by choosing one of these options:

* rename all listed files
* skip all listed files
* confirm each renaming individually

Additional CLI options might be provided to the command:

* `--tree`: Show discovered template files as file tree instead of path listing
* `--extensions=...`: Comma-separated list of file extensions that should be
  considered potential Fluid templates (default: `html,txt,xml,json`)
* `--include-tests`: Also process files in `Tests/` directory, which are skipped
  by default
