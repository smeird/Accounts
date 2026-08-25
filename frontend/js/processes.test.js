const assert = require('assert');
const fs = require('fs');
const path = require('path');

const frontendDirectory = path.resolve(__dirname, '..');
const processesPage = fs.readFileSync(path.join(frontendDirectory, 'processes.html'), 'utf8');
const processesCss = fs.readFileSync(path.join(frontendDirectory, 'processes.css'), 'utf8');
const processesJs = fs.readFileSync(path.join(__dirname, 'processes.js'), 'utf8');
const menuJs = fs.readFileSync(path.join(__dirname, 'menu.js'), 'utf8');
const sidebarCss = fs.readFileSync(path.join(frontendDirectory, 'sidebar.css'), 'utf8');

assert.match(menuJs, /app-shell-root[\s\S]*app-shell-body[\s\S]*app-shell[\s\S]*app-shell-main/, 'the authenticated layout should expose stable shell hooks');
assert.match(menuJs, /sidebar\.css\?v=20260825-ipad-shell/, 'the shared shell stylesheet cache key should include the tablet fix');
assert.match(sidebarCss, /@supports \(height:100dvh\)[\s\S]*height:100dvh!important/, 'the application shell should follow the live mobile browser viewport');
assert.match(sidebarCss, /\.app-shell-main[\s\S]*overscroll-behavior-y:contain/, 'the main touch scroller should not chain into the page');
assert.match(processesCss, /\.processes-main[\s\S]*safe-area-inset-bottom/, 'Automation Centre should preserve tappable space below its final action');
assert.match(processesJs, /resetPanel\?\.addEventListener\('toggle'[\s\S]*scrollIntoView\([\s\S]*block: 'nearest'/, 'opening the reset panel should reveal its action on tablet browsers');
assert.match(processesPage, /processes\.css\?v=20260825-ipad-scroll/, 'the Automation Centre stylesheet cache key should include the tablet fix');
assert.match(processesPage, /processes\.js\?v=20260825-ipad-scroll/, 'the Automation Centre script cache key should include the tablet fix');

console.log('processes tablet scroll tests passed');
