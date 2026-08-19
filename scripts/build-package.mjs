import { execFileSync } from "node:child_process";
import { createWriteStream } from "node:fs";
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import archiver from "archiver";

const root = resolve(import.meta.dirname, "..");
const pluginSlug = "beplus-scss-compiler";
const buildDir = join(root, "build");
const stagingDir = join(buildDir, pluginSlug);
const version = JSON.parse(readFileSync(join(root, "package.json"), "utf8")).version;
const zipPath = join(buildDir, `${pluginSlug}-${version}.zip`);

const publishable = [
	"src",
	"languages",
	"beplus-scss-compiler.php",
	"uninstall.php",
	"readme.txt",
	"composer.json",
	"composer.lock",
];

try {
	rmSync(buildDir, { recursive: true, force: true });
	mkdirSync(stagingDir, { recursive: true });

	for (const entry of publishable) {
		const src = join(root, entry);
		if (!existsSync(src)) {
			throw new Error(`Missing release file/dir: ${entry}`);
		}
		cpSync(src, join(stagingDir, entry), { recursive: true });
	}

	// Resolve production dependencies (vendor/) into the staging tree.
	execFileSync("composer", ["install", "--no-dev", "--optimize-autoloader"], {
		cwd: stagingDir,
		stdio: "inherit",
	});

	const output = createWriteStream(zipPath);
	const archive = archiver("zip", { zlib: { level: 9 } });
	await new Promise((resolvePromise, rejectPromise) => {
		archive.on("error", rejectPromise);
		output.on("close", resolvePromise);
		archive.pipe(output);
		archive.directory(stagingDir, pluginSlug);
		archive.finalize().catch(rejectPromise);
	});

	console.log(`Built ${zipPath} (${formatSize(statSync(zipPath).size)})`);
} finally {
	rmSync(stagingDir, { recursive: true, force: true });
}

function formatSize(bytes) {
	const mb = bytes / (1024 * 1024);
	if (mb >= 1) {
		return `${mb.toFixed(2)} MB`;
	}
	return `${(bytes / 1024).toFixed(1)} KB`;
}
