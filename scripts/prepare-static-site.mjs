import { cp, mkdir, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';

const output = 'dist';
await rm(output, { recursive: true, force: true });
await mkdir(output, { recursive: true });

for (const file of [
  'index.html', 'login.html', 'signup.html', 'dashboard.html', 'apartments.html',
  'rooms.html', 'tenants.html', 'leases.html', 'payments.html', 'rent_deposits.html',
  'maintenance.html', 'reports.html', 'settings.html', 'users.html',
]) {
  if (existsSync(file)) await cp(file, `${output}/${file}`);
}

await cp('assets', `${output}/assets`, { recursive: true });
