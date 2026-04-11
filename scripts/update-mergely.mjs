import { copyFile, readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const rootDir = path.dirname(fileURLToPath(import.meta.url));
const repoDir = path.resolve(rootDir, "..");
const source = path.join(repoDir, "node_modules", "mergely", "lib", "mergely.min.js");
const destination = path.join(repoDir, "plugins", "versions", "js", "mergely.min.js");

const [sourceContent, destinationContent] = await Promise.all([
  readFile(source, "utf8"),
  readFile(destination, "utf8").catch((error) => {
    if (error.code === "ENOENT") {
      return null;
    }
    throw error;
  }),
]);

if (destinationContent === sourceContent) {
  console.log("mergely.min.js is already up to date");
} else {
  await copyFile(source, destination);
  console.log("mergely.min.js updated from installed npm dependency");
}

