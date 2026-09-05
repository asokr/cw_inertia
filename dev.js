#!/usr/bin/env node
/**
 * Локальный запуск проекта одной командой.
 *
 * По умолчанию:
 *   - php artisan serve
 *   - Vite HMR (порт 3001)
 *   - queue:listen по всем именованным очередям
 *   - schedule:work (cron каждую минуту)
 *
 *   node dev.js
 *   node dev.js --no-serve       # без artisan serve
 *   node dev.js --workers=split  # отдельный воркер на каждую очередь
 */

import { spawn, execFileSync, execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const isWin = process.platform === 'win32';

const RESET = '\x1b[0m';
const BOLD = '\x1b[1m';
const DIM = '\x1b[2m';
const RED = '\x1b[31m';
const GREEN = '\x1b[32m';
const YELLOW = '\x1b[33m';
const CYAN = '\x1b[36m';
const MAGENTA = '\x1b[35m';
const WHITE = '\x1b[37m';

/** Все именованные очереди проекта, приоритет слева направо. См. docs/queues.md */
const ALL_QUEUES =
    'wb_ab_testing,oz_ab_testing,oz_stock_history,price_calc,profitability,repricer_stocks,wb_ai_cabinet_analyzer,oz_ai_cabinet_analyzer,default';

/** Отдельные воркеры — как в docs/queues.md, для --workers=split */
const SPLIT_WORKERS = [
    { name: 'queue:default', args: ['queue:listen', '--queue=default', '--tries=3', '--timeout=3600'] },
    { name: 'queue:price', args: ['queue:listen', '--queue=price_calc', '--tries=1', '--timeout=2700'] },
    { name: 'queue:profit', args: ['queue:listen', '--queue=profitability', '--tries=1', '--timeout=1800'] },
    { name: 'queue:repricer', args: ['queue:listen', '--queue=repricer_stocks', '--timeout=1500'] },
    { name: 'queue:wb-ab', args: ['queue:listen', '--queue=wb_ab_testing', '--tries=1', '--timeout=120'] },
    { name: 'queue:oz-ab', args: ['queue:listen', '--queue=oz_ab_testing', '--tries=1', '--timeout=120'] },
    { name: 'queue:oz-stocks', args: ['queue:listen', '--queue=oz_stock_history', '--tries=2', '--timeout=1800'] },
    { name: 'queue:wb-ai', args: ['queue:listen', '--queue=wb_ai_cabinet_analyzer', '--tries=3', '--timeout=3600', '--sleep=1'] },
    { name: 'queue:oz-ai', args: ['queue:listen', '--queue=oz_ai_cabinet_analyzer', '--tries=3', '--timeout=3600', '--sleep=1'] },
];

const COLORS = {
    vite: CYAN,
    queue: YELLOW,
    schedule: MAGENTA,
    serve: GREEN,
    'queue:default': YELLOW,
    'queue:price': YELLOW,
    'queue:profit': YELLOW,
    'queue:repricer': YELLOW,
    'queue:wb-ab': YELLOW,
    'queue:oz-ab': YELLOW,
    'queue:wb-ai': YELLOW,
    'queue:oz-ai': YELLOW,
};

function parseArgs(argv) {
    const flags = {
        serve: true,
        queue: true,
        schedule: true,
        vite: true,
        workers: 'one',
        help: false,
        invalid: false,
    };

    for (const arg of argv) {
        if (arg === '-h' || arg === '--help') flags.help = true;
        else if (arg === '--serve') flags.serve = true;
        else if (arg === '--no-serve') flags.serve = false;
        else if (arg === '--no-queue') flags.queue = false;
        else if (arg === '--no-schedule') flags.schedule = false;
        else if (arg === '--no-vite') flags.vite = false;
        else if (arg === '--workers=split' || arg === '--split') flags.workers = 'split';
        else if (arg === '--workers=one') flags.workers = 'one';
        else {
            console.error(`${RED}Неизвестный аргумент: ${arg}${RESET}`);
            flags.help = true;
            flags.invalid = true;
        }
    }

    return flags;
}

function printHelp() {
    console.log(`
${BOLD}Локальный запуск CW Platform${RESET}

  ${CYAN}node dev.js${RESET} [опции]

По умолчанию поднимает artisan serve, Vite, очередь и планировщик.
OSPanel используется только как MySQL.

Опции:
  --no-serve         без php artisan serve
  --workers=split    отдельный воркер на каждую очередь (иначе один общий)
  --no-queue         без воркера очереди
  --no-schedule      без планировщика
  --no-vite          без Vite
  -h, --help         эта справка

Остановка: Ctrl+C
`);
}

function which(cmd) {
    try {
        const bin = isWin ? 'where.exe' : 'which';
        const out = execFileSync(bin, [cmd], { encoding: 'utf8' });
        return out.split(/\r?\n/).map((s) => s.trim()).find(Boolean) || null;
    } catch {
        return null;
    }
}

/** PHP из PATH или типичный путь OSPanel (8.3+). */
function resolvePhp() {
    if (process.env.PHP_BINARY && fs.existsSync(process.env.PHP_BINARY)) {
        return process.env.PHP_BINARY;
    }

    const fromPath = which('php');
    if (fromPath) return fromPath;

    const modules = 'C:\\OSPanel\\modules';
    if (!fs.existsSync(modules)) return null;

    const dirs = fs
        .readdirSync(modules)
        .filter((d) => /^PHP-8\.[3-9]/.test(d) && !d.includes('FCGI'));

    dirs.sort().reverse();

    for (const dir of dirs) {
        const candidate = path.join(modules, dir, 'PHP', 'php.exe');
        if (fs.existsSync(candidate)) return candidate;
    }

    return null;
}

function fail(message) {
    console.error(`${RED}${BOLD}Ошибка:${RESET} ${message}`);
    process.exit(1);
}

function logInfo(message) {
    console.log(`${DIM}${message}${RESET}`);
}

function padName(name) {
    return name.padEnd(10, ' ');
}

const children = [];
let shuttingDown = false;

function killTree(child) {
    if (!child?.pid || child.killed) return;

    if (isWin) {
        try {
            execSync(`taskkill /pid ${child.pid} /T /F`, { stdio: 'ignore' });
        } catch {
            // процесс уже завершился
        }
        return;
    }

    try {
        child.kill('SIGTERM');
    } catch {
        // процесс уже завершился
    }
}

function shutdown(code = 0) {
    if (shuttingDown) return;
    shuttingDown = true;
    console.log(`\n${BOLD}Останавливаю процессы…${RESET}`);

    for (const child of children) {
        killTree(child);
    }

    setTimeout(() => process.exit(code), 300);
}

function pipeLines(stream, name) {
    const color = COLORS[name] || WHITE;
    const rl = readline.createInterface({ input: stream });
    rl.on('line', (line) => {
        process.stdout.write(`${color}${padName(name)}${RESET} ${line}\n`);
    });
}

function startProcess({ name, command, args, shell = false }) {
    const child = spawn(command, args, {
        cwd: root,
        env: process.env,
        shell,
        windowsHide: true,
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    child.stdout.setEncoding('utf8');
    child.stderr.setEncoding('utf8');
    pipeLines(child.stdout, name);
    pipeLines(child.stderr, name);

    child.on('exit', (code, signal) => {
        if (shuttingDown) return;

        const reason = signal ? `сигнал ${signal}` : `код ${code ?? 0}`;
        console.log(`${RED}${padName(name)}${RESET} процесс завершился (${reason})`);
        shutdown(code && code !== 0 ? code : 0);
    });

    child.on('error', (err) => {
        console.error(`${RED}${padName(name)}${RESET} не удалось запустить: ${err.message}`);
        shutdown(1);
    });

    children.push(child);
    return child;
}

function artisan(php, args) {
    return [php, 'artisan', ...args];
}

const flags = parseArgs(process.argv.slice(2));

if (flags.help) {
    printHelp();
    process.exit(flags.invalid ? 1 : 0);
}

if (!fs.existsSync(path.join(root, 'artisan'))) {
    fail('не найден artisan — запустите скрипт из корня проекта');
}

if (!fs.existsSync(path.join(root, '.env'))) {
    fail('нет файла .env — скопируйте .env.example и заполните настройки');
}

if (!fs.existsSync(path.join(root, 'vendor', 'autoload.php'))) {
    fail('нет vendor/ — выполните composer install');
}

if (flags.vite && !fs.existsSync(path.join(root, 'node_modules'))) {
    fail('нет node_modules/ — выполните npm install');
}

const php = resolvePhp();
if ((flags.queue || flags.schedule || flags.serve) && !php) {
    fail('PHP не найден. Добавьте php в PATH или задайте PHP_BINARY');
}

const processes = [];

if (flags.serve) {
    const [command, ...args] = artisan(php, ['serve']);
    processes.push({ name: 'serve', command, args });
}

if (flags.vite) {
    processes.push({
        name: 'vite',
        command: isWin ? 'cmd.exe' : 'npm',
        args: isWin ? ['/c', 'npm', 'run', 'dev'] : ['run', 'dev'],
    });
}

if (flags.queue) {
    if (flags.workers === 'split') {
        for (const worker of SPLIT_WORKERS) {
            const [command, ...args] = artisan(php, worker.args);
            processes.push({ name: worker.name, command, args });
        }
    } else {
        const [command, ...args] = artisan(php, [
            'queue:listen',
            `--queue=${ALL_QUEUES}`,
            '--tries=3',
            '--timeout=3600',
        ]);
        processes.push({ name: 'queue', command, args });
    }
}

if (flags.schedule) {
    const [command, ...args] = artisan(php, ['schedule:work']);
    processes.push({ name: 'schedule', command, args });
}

if (processes.length === 0) {
    fail('нечего запускать: все процессы отключены флагами');
}

console.log(`${BOLD}${GREEN}Локальная разработка CW Platform${RESET}\n`);
logInfo(`каталог:  ${root}`);
if (php) logInfo(`PHP:      ${php}`);
logInfo(`HTTP:     ${flags.serve ? 'php artisan serve (http://127.0.0.1:8000)' : 'выключен'}`);
logInfo(`Vite:     ${flags.vite ? 'порт 3001' : 'выключен'}`);
logInfo(
    `очередь:  ${
        flags.queue
            ? flags.workers === 'split'
                ? `${SPLIT_WORKERS.length} воркеров (split)`
                : 'один queue:listen по всем очередям'
            : 'выключена'
    }`,
);
logInfo(`cron:     ${flags.schedule ? 'schedule:work' : 'выключен'}`);
console.log(`${DIM}остановка: Ctrl+C${RESET}\n`);

for (const proc of processes) {
    startProcess(proc);
}

process.on('SIGINT', () => shutdown(0));
process.on('SIGTERM', () => shutdown(0));

if (isWin) {
    // На Windows Ctrl+C не всегда даёт SIGINT без readline
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    rl.on('SIGINT', () => process.emit('SIGINT'));
}
