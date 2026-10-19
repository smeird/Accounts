// Build assets locally; production needs neither npm nor a CDN for its shell.
const fs = require('node:fs');
const path = require('node:path');
const sharp = require('sharp');
async function build() {
  const source = path.dirname(require.resolve('@fortawesome/fontawesome-free/package.json'));
  const destination = 'frontend/vendor/fontawesome';
  fs.mkdirSync(`${destination}/css`, { recursive: true });
  fs.copyFileSync(`${source}/css/all.min.css`, `${destination}/css/all.min.css`);
  fs.cpSync(`${source}/webfonts`, `${destination}/webfonts`, { recursive: true });
  fs.copyFileSync(`${source}/LICENSE.txt`, `${destination}/LICENSE.txt`);
  fs.copyFileSync(path.join(path.dirname(require.resolve('tailwindcss/package.json')), 'LICENSE'), 'frontend/vendor/tailwind/LICENSE');
  for (const [name, asset, licence] of [['papaparse', 'papaparse.min.js', 'LICENSE'], ['file-saver', 'dist/FileSaver.min.js', 'LICENSE.md']]) {
    const directory = path.dirname(require.resolve(`${name}/package.json`));
    const target = `frontend/vendor/${name}`;
    fs.mkdirSync(target, { recursive: true });
    fs.copyFileSync(`${directory}/${asset}`, `${target}/${path.basename(asset)}`);
    fs.copyFileSync(`${directory}/${licence}`, `${target}/LICENSE`);
  }
  for (const size of [180, 192, 512]) {
    await sharp('frontend/icons/app.svg').resize(size, size).png().toFile(`frontend/icons/app-${size}.png`);
  }
}
build().catch(error => { console.error(error); process.exitCode = 1; });
