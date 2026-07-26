import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import {
	copyFileSync,
	existsSync,
	mkdirSync,
	readFileSync,
	readdirSync,
	rmSync,
	statSync,
	unlinkSync,
} from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const slug = 'promoguard-for-woocommerce';
const version = JSON.parse( readFileSync( join( root, 'package.json' ), 'utf8' ) ).version;
const workDirectory = join( root, '.release-tmp' );
const packageDirectory = join( workDirectory, slug );
const distDirectory = join( root, 'dist' );
const archivePath = join( distDirectory, `${ slug }-${ version }.zip` );

function normalized( path ) {
	return path.split( sep ).join( '/' );
}

const exclusions = readFileSync( join( root, '.distignore' ), 'utf8' )
	.split( /\r?\n/ )
	.map( ( rule ) => rule.trim().replace( /^\/+|\/+$/g, '' ) )
	.filter( ( rule ) => rule && ! rule.startsWith( '#' ) );

function isExcluded( sourcePath ) {
	const projectPath = normalized( relative( root, sourcePath ) );
	return exclusions.some( ( rule ) => projectPath === rule || projectPath.startsWith( `${ rule }/` ) );
}

function copyDistributionFiles( sourceDirectory, targetDirectory ) {
	mkdirSync( targetDirectory, { recursive: true } );
	readdirSync( sourceDirectory, { withFileTypes: true } ).forEach( ( entry ) => {
		const sourcePath = join( sourceDirectory, entry.name );
		if ( isExcluded( sourcePath ) ) {
			return;
		}

		const targetPath = join( targetDirectory, entry.name );
		if ( entry.isDirectory() ) {
			copyDistributionFiles( sourcePath, targetPath );
		} else if ( entry.isFile() ) {
			copyFileSync( sourcePath, targetPath );
		}
	} );
}

function runComposerInstall() {
	const composerFiles = [ 'composer.json', 'composer.lock' ];
	composerFiles.forEach( ( file ) => copyFileSync( join( root, file ), join( packageDirectory, file ) ) );

	const composerArguments = [
		'install',
		'--no-dev',
		'--classmap-authoritative',
		'--no-interaction',
		'--no-progress',
		'--no-scripts',
		'--no-plugins',
	];
	const composerCommand = process.platform === 'win32'
		? [ process.env.ComSpec || 'cmd.exe', [ '/d', '/s', '/c', 'composer.bat', ...composerArguments ] ]
		: [ 'composer', composerArguments ];

	execFileSync( composerCommand[0], composerCommand[1], {
		cwd: packageDirectory,
		stdio: 'inherit',
	} );
}

function createArchive() {
	mkdirSync( distDirectory, { recursive: true } );
	if ( existsSync( archivePath ) ) {
		unlinkSync( archivePath );
	}

	if ( process.platform === 'win32' ) {
		execFileSync(
			'tar.exe',
			[ '-a', '-c', '-f', archivePath, '-C', workDirectory, slug ],
			{ stdio: 'inherit' }
		);
		return;
	}

	execFileSync( 'zip', [ '-q', '-r', archivePath, slug ], {
		cwd: workDirectory,
		stdio: 'inherit',
	} );
}

function verifyStagingTree() {
	const required = [
		'promoguard-for-woocommerce.php',
		'uninstall.php',
		'readme.txt',
		'LICENSE',
		'composer.json',
		'composer.lock',
		'vendor/autoload.php',
		'assets/build/index.js',
		'assets/build/index.css',
		'assets/build/index.asset.php',
	];
	const forbidden = [
		'tests',
		'tools',
		'node_modules',
		'package.json',
		'package-lock.json',
		'.distignore',
		'.git',
	];

	required.forEach( ( path ) => {
		if ( ! existsSync( join( packageDirectory, path ) ) ) {
			throw new Error( `Release package is missing ${ path }.` );
		}
	} );
	forbidden.forEach( ( path ) => {
		if ( existsSync( join( packageDirectory, path ) ) ) {
			throw new Error( `Release package unexpectedly contains ${ path }.` );
		}
	} );
}

function directorySize( directory ) {
	return readdirSync( directory, { withFileTypes: true } ).reduce( ( total, entry ) => {
		const path = join( directory, entry.name );
		return total + ( entry.isDirectory() ? directorySize( path ) : statSync( path ).size );
	}, 0 );
}

rmSync( workDirectory, { recursive: true, force: true } );
mkdirSync( packageDirectory, { recursive: true } );
copyDistributionFiles( root, packageDirectory );
runComposerInstall();
verifyStagingTree();
createArchive();

const checksum = createHash( 'sha256' ).update( readFileSync( archivePath ) ).digest( 'hex' );
console.log( `Built ${ archivePath }` );
console.log( `Uncompressed bytes: ${ directorySize( packageDirectory ) }` );
console.log( `SHA-256: ${ checksum }` );

rmSync( workDirectory, { recursive: true, force: true } );
