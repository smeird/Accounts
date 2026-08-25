const assert = require('assert');
const fs = require('fs');
const path = require('path');

const projectDirectory = path.resolve(__dirname, '..', '..');
const frontendDirectory = path.resolve(__dirname, '..');
const menuJs = fs.readFileSync(path.join(__dirname, 'menu.js'), 'utf8');
const sidebarCss = fs.readFileSync(path.join(frontendDirectory, 'sidebar.css'), 'utf8');
const utilityCss = fs.readFileSync(path.join(frontendDirectory, 'utility_refresh.css'), 'utf8');
const cutoverCss = fs.readFileSync(path.join(frontendDirectory, 'tag_taxonomy_cutover.css'), 'utf8');

const pageFiles = [
  ...fs.readdirSync(frontendDirectory)
    .filter(file => /\.(?:html|php)$/.test(file))
    .map(file => path.join(frontendDirectory, file)),
  ...fs.readdirSync(projectDirectory)
    .filter(file => /\.php$/.test(file))
    .map(file => path.join(projectDirectory, file)),
];

const shellPages = pageFiles.filter(file => /(?:frontend\/)?js\/menu\.js/.test(fs.readFileSync(file, 'utf8')));
assert.ok(shellPages.length > 0, 'authenticated shell pages should be discoverable');

shellPages.forEach(file => {
  const page = fs.readFileSync(file, 'utf8');
  assert.match(page, /<body\b[^>]*>\s*<div\b[^>]*class=["'][^"']*\bflex\b/i, `${path.basename(file)} should expose the shared shell wrapper`);
  assert.match(page, /<main\b/i, `${path.basename(file)} should expose the shared scrolling panel`);
  assert.match(page, /<meta\b[^>]*name=["']viewport["']/i, `${path.basename(file)} should declare a mobile viewport`);
});

assert.match(menuJs, /const main = document\.querySelector\('body > div > main'\);[\s\S]*const content = main\?\.parentElement/, 'shell setup should locate the main panel instead of depending on an existing wrapper utility class');
assert.match(menuJs, /sidebar\.css\?v=20260825-sitewide-ipad-shell/, 'the site-wide shell stylesheet should have a fresh cache key');
assert.match(sidebarCss, /\.app-shell-root[\s\S]*overflow:hidden[\s\S]*overscroll-behavior:none/, 'the document should not become a competing touch scroller');
assert.match(sidebarCss, /@supports \(height:100dvh\)[\s\S]*height:100dvh!important/, 'the shell should follow the usable mobile browser viewport');
assert.match(sidebarCss, /\.app-shell-main[\s\S]*overscroll-behavior-y:contain[\s\S]*scroll-padding-block-end:max\([^;]*safe-area-inset-bottom/, 'the main panel should contain touch scrolling and preserve bottom reveal space');
assert.match(utilityCss, /\.admin-refresh-page \.admin-actions\{[^}]*bottom:max\([^;]*safe-area-inset-bottom/, 'admin sticky actions should clear the device safe area');
assert.match(cutoverCss, /\.cutover-action-bar\{[^}]*bottom:max\([^;]*safe-area-inset-bottom/, 'cutover sticky actions should clear the device safe area');

console.log(`application shell tests passed (${shellPages.length} pages audited)`);
