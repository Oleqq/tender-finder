import { cpSync, mkdirSync, rmSync } from 'node:fs';

rmSync('dist', { force: true, recursive: true });
mkdirSync('dist', { recursive: true });
cpSync('public/build', 'dist/build', { recursive: true });
