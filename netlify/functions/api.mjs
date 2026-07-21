import mysql from 'mysql2/promise';
import bcrypt from 'bcryptjs';
import { SignJWT, jwtVerify } from 'jose';

let pool;
const permissions = {
  'Super Admin': ['*'],
  Manager: ['dashboard.view', 'payments.view', 'payments.update', 'reports.view', 'reports.generate', 'settings.password'],
  Staff: ['dashboard.view', 'apartments.view', 'rooms.view', 'tenants.view', 'tenants.create', 'leases.view', 'leases.create', 'payments.view', 'payments.create', 'rent_deposits.view', 'rent_deposits.create', 'maintenance.view', 'maintenance.create', 'reports.view'],
};

function db() {
  if (!pool) {
    const { DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_PORT = '3306' } = process.env;
    if (!DB_HOST || !DB_NAME || !DB_USER || !DB_PASSWORD) throw new Error('Database environment variables are not configured.');
    pool = mysql.createPool({ host: DB_HOST, port: Number(DB_PORT), user: DB_USER, password: DB_PASSWORD, database: DB_NAME, waitForConnections: true, connectionLimit: 5, ssl: process.env.DB_SSL === 'false' ? undefined : { rejectUnauthorized: true } });
  }
  return pool;
}
function secret() { return new TextEncoder().encode(process.env.JWT_SECRET || ''); }
function json(data, status = 200, headers = {}) { return new Response(JSON.stringify(data), { status, headers: { 'content-type': 'application/json; charset=utf-8', ...headers } }); }
function fail(message, status = 400) { return json({ success: false, message }, status); }
function cookies(request) { return Object.fromEntries((request.headers.get('cookie') || '').split(/; */).filter(Boolean).map(v => { const i = v.indexOf('='); return [v.slice(0, i), decodeURIComponent(v.slice(i + 1))]; })); }
async function userFrom(request) {
  const token = cookies(request).remis_session;
  if (!token || !process.env.JWT_SECRET) return null;
  try { return (await jwtVerify(token, secret())).payload.user; } catch { return null; }
}
async function session(user) { return new SignJWT({ user }).setProtectedHeader({ alg: 'HS256' }).setIssuedAt().setExpirationTime('8h').sign(secret()); }
function can(user, permission) { const p = permissions[user?.role] || []; return p.includes('*') || p.includes(permission); }
function action(method) { return ({ GET: 'view', POST: 'create', PUT: 'update', DELETE: 'delete' })[method] || 'view'; }
async function body(request) { try { return await request.json(); } catch { return {}; } }
function route(request) { const url = new URL(request.url); return url.pathname.split('/').pop().replace(/\.php$/, ''); }

async function requireUser(request, permission) {
  const user = await userFrom(request);
  if (!user) return [null, fail('Authentication required. Please log in.', 401)];
  if (permission && !can(user, permission)) return [null, fail('You do not have permission to perform this action.', 403)];
  return [user, null];
}

async function login(request) {
  if (request.method !== 'POST') return fail('Method not allowed.', 405);
  const input = await body(request), email = String(input.email || '').trim().toLowerCase(), password = String(input.password || '');
  if (!email || !password) return fail('Email and password are required.');
  const [rows] = await db().execute('SELECT id, full_name, email, password, role FROM users WHERE email = ? AND is_active = 1 LIMIT 1', [email]);
  const row = rows[0];
  if (!row || !(await bcrypt.compare(password, row.password))) return fail('Invalid email or password.', 401);
  const user = { id: Number(row.id), name: row.full_name, email: row.email, role: row.role };
  const token = await session(user);
  return json({ success: true, message: 'Login successful.', user, permissions: permissions[user.role] || [] }, 200, { 'set-cookie': `remis_session=${token}; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=28800` });
}
async function signup(request) {
  if (request.method !== 'POST') return fail('Method not allowed.', 405);
  const i = await body(request), name = String(i.full_name || '').trim(), email = String(i.email || '').trim().toLowerCase(), password = String(i.password || '');
  if (!name || !email || !password) return fail('All required fields must be filled.');
  if (!/^\S+@\S+\.\S+$/.test(email)) return fail('Please enter a valid email address.');
  if (password.length < 6) return fail('Password must be at least 6 characters.');
  if (password !== i.confirm_password) return fail('Passwords do not match.');
  const [exists] = await db().execute('SELECT id FROM users WHERE email = ? LIMIT 1', [email]);
  if (exists.length) return fail('An account with this email already exists.', 409);
  const [result] = await db().execute('INSERT INTO users (full_name, email, password, phone, role) VALUES (?, ?, ?, ?, ?)', [name, email, await bcrypt.hash(password, 12), String(i.phone || '').trim() || null, 'Staff']);
  return json({ success: true, message: 'Account created successfully. You can now sign in.', user: { id: Number(result.insertId), name, email, role: 'Staff' } }, 201);
}
async function me(request) {
  if (request.method !== 'GET') return fail('Method not allowed.', 405);
  const [user, denied] = await requireUser(request); if (denied) return denied;
  const [rows] = await db().execute('SELECT id, full_name, email, phone, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1', [user.id]);
  if (!rows[0]) return fail('Session expired. Please log in again.', 401);
  const fresh = { id: Number(rows[0].id), name: rows[0].full_name, email: rows[0].email, phone: rows[0].phone, role: rows[0].role };
  return json({ success: true, message: 'Authenticated.', user: fresh, permissions: permissions[fresh.role] || [] });
}
async function logout(request) { if (request.method !== 'POST') return fail('Method not allowed.', 405); return json({ success: true, message: 'Logged out successfully.' }, 200, { 'set-cookie': 'remis_session=; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=0' }); }

const entities = {
  apartments: { table: 'apartments', key: 'apartment', list: 'apartments', permission: 'apartments', fields: ['name','address','city','description','total_floors','monthly_rent_amount','rent_deposit_amount','is_active'], select: 'SELECT a.*, (SELECT COUNT(*) FROM rooms r WHERE r.apartment_id=a.id) room_count, (SELECT COUNT(*) FROM rooms r WHERE r.apartment_id=a.id AND r.status="occupied") occupied_count FROM apartments a' },
  rooms: { table: 'rooms', key: 'room', list: 'rooms', permission: 'rooms', fields: ['apartment_id','room_number','floor','rent_amount','bedrooms','bathrooms','status','description','listed_date'], select: 'SELECT r.*, a.name apartment_name FROM rooms r JOIN apartments a ON a.id=r.apartment_id' },
  tenants: { table: 'tenants', key: 'tenant', list: 'tenants', permission: 'tenants', fields: ['full_name','email','phone','id_number','emergency_contact','emergency_phone','notes','registered_date','is_active'], select: 'SELECT t.*, (SELECT COUNT(*) FROM leases l WHERE l.tenant_id=t.id AND l.status="active") active_leases FROM tenants t' },
  maintenance: { table: 'maintenance_requests', key: 'request', list: 'requests', permission: 'maintenance', fields: ['apartment_id','room_id','title','description','priority','status','reported_date','completed_date','cost'], select: 'SELECT m.*, a.name apartment_name, r.room_number FROM maintenance_requests m LEFT JOIN apartments a ON a.id=m.apartment_id LEFT JOIN rooms r ON r.id=m.room_id' },
  rent_deposits: { table: 'rent_deposits', key: 'deposit', list: 'deposits', permission: 'rent_deposits', fields: ['tenant_id','apartment_id','room_id','lease_id','amount_paid','date_paid','payment_method','reference_number','notes'], select: 'SELECT rd.*, t.full_name tenant_name, a.name apartment_name, r.room_number FROM rent_deposits rd JOIN tenants t ON t.id=rd.tenant_id JOIN apartments a ON a.id=rd.apartment_id JOIN rooms r ON r.id=rd.room_id' },
};
function clean(i, fields) { const o = {}; for (const f of fields) if (Object.hasOwn(i, f)) o[f] = i[f] === '' ? null : i[f]; return o; }
async function entity(request, name) {
  const c = entities[name], [user, denied] = await requireUser(request, `${c.permission}.${action(request.method)}`); if (denied) return denied;
  const url = new URL(request.url), id = Number(url.searchParams.get('id') || 0), sql = db();
  if (request.method === 'GET') {
    let query = c.select, params = [];
    if (id) { query += ' WHERE ' + (name === 'apartments' ? 'a.id' : name === 'rooms' ? 'r.id' : name === 'tenants' ? 't.id' : name === 'maintenance' ? 'm.id' : 'rd.id') + ' = ?'; params = [id]; }
    else if (name === 'rooms' && url.searchParams.get('apartment_id')) { query += ' WHERE r.apartment_id = ?'; params = [Number(url.searchParams.get('apartment_id'))]; }
    query += ' ORDER BY id DESC'; const [rows] = await sql.execute(query, params);
    return json({ success: true, message: `${c.list} loaded.`, [id ? c.key : c.list]: id ? rows[0] || null : rows }, rows.length || !id ? 200 : 404);
  }
  const input = await body(request), values = clean(input, c.fields);
  if (request.method === 'POST') {
    const keys = Object.keys(values); if (!keys.length) return fail('No valid data was provided.');
    const [result] = await sql.execute(`INSERT INTO ${c.table} (${keys.join(',')}) VALUES (${keys.map(() => '?').join(',')})`, keys.map(k => values[k]));
    return json({ success: true, message: `${c.key} created successfully.`, [c.key]: { id: Number(result.insertId) } }, 201);
  }
  if (request.method === 'PUT') {
    const target = Number(input.id || 0), keys = Object.keys(values); if (!target || !keys.length) return fail('A valid ID and update data are required.');
    const [result] = await sql.execute(`UPDATE ${c.table} SET ${keys.map(k => `${k} = ?`).join(', ')} WHERE id = ?`, [...keys.map(k => values[k]), target]);
    return result.affectedRows ? json({ success: true, message: `${c.key} updated successfully.` }) : fail(`${c.key} not found.`, 404);
  }
  if (request.method === 'DELETE') { if (!id) return fail('A valid ID is required.'); const [result] = await sql.execute(`DELETE FROM ${c.table} WHERE id = ?`, [id]); return result.affectedRows ? json({ success: true, message: `${c.key} deleted successfully.` }) : fail(`${c.key} not found.`, 404); }
  return fail('Method not allowed.', 405);
}
async function leases(request) {
  const [user, denied] = await requireUser(request, `leases.${action(request.method)}`); if (denied) return denied;
  const url = new URL(request.url), id = Number(url.searchParams.get('id') || 0), sql = db();
  const select = 'SELECT l.*, t.full_name tenant_name, r.room_number, a.name apartment_name FROM leases l JOIN tenants t ON t.id=l.tenant_id JOIN rooms r ON r.id=l.room_id JOIN apartments a ON a.id=r.apartment_id';
  if (request.method === 'GET') { let q = select + (id ? ' WHERE l.id = ?' : ' ORDER BY l.start_date DESC'); const [rows] = await sql.execute(q, id ? [id] : []); return json({ success: true, message: 'Leases loaded.', [id ? 'lease' : 'leases']: id ? rows[0] || null : rows }, rows.length || !id ? 200 : 404); }
  const i = await body(request), fields = ['tenant_id','room_id','start_date','end_date','monthly_rent','deposit_amount','status','notes'], v = clean(i, fields);
  if (request.method === 'POST') { const [r] = await sql.execute(`INSERT INTO leases (${Object.keys(v).join(',')}) VALUES (${Object.keys(v).map(()=>'?').join(',')})`, Object.values(v)); await sql.execute('UPDATE rooms SET status="occupied" WHERE id=?', [v.room_id]); return json({success:true,message:'Lease created successfully.',lease:{id:Number(r.insertId)}},201); }
  if (request.method === 'PUT') { const target=Number(i.id||0), keys=Object.keys(v); const [r]=await sql.execute(`UPDATE leases SET ${keys.map(k=>`${k}=?`).join(',')} WHERE id=?`, [...keys.map(k=>v[k]),target]); return r.affectedRows ? json({success:true,message:'Lease updated successfully.'}) : fail('Lease not found.',404); }
  if (request.method === 'DELETE') { if(!id) return fail('A valid ID is required.'); const [r]=await sql.execute('DELETE FROM leases WHERE id=?',[id]); return r.affectedRows ? json({success:true,message:'Lease deleted successfully.'}) : fail('Lease not found.',404); }
  return fail('Method not allowed.',405);
}
async function payments(request) {
  const [user, denied] = await requireUser(request, `payments.${action(request.method)}`); if (denied) return denied;
  const url=new URL(request.url), id=Number(url.searchParams.get('id')||0), sql=db(), select='SELECT p.*, t.full_name tenant_name, r.room_number, a.name apartment_name FROM payments p JOIN tenants t ON t.id=p.tenant_id JOIN leases l ON l.id=p.lease_id JOIN rooms r ON r.id=l.room_id JOIN apartments a ON a.id=r.apartment_id';
  if(request.method==='GET'){const [rows]=await sql.execute(select+(id?' WHERE p.id=?':' ORDER BY p.payment_date DESC'),id?[id]:[]);return json({success:true,message:'Payments loaded.',[id?'payment':'payments']:id?rows[0]||null:rows},rows.length||!id?200:404);}
  const i=await body(request), fields=['lease_id','tenant_id','amount','payment_date','payment_method','status','reference_number','notes'],v=clean(i,fields);
  if(request.method==='POST'){const [r]=await sql.execute(`INSERT INTO payments (${Object.keys(v).join(',')}) VALUES (${Object.keys(v).map(()=>'?').join(',')})`,Object.values(v));return json({success:true,message:'Payment created successfully.',payment:{id:Number(r.insertId)}},201);}
  if(request.method==='PUT'){const target=Number(i.id||0),keys=Object.keys(v),[r]=await sql.execute(`UPDATE payments SET ${keys.map(k=>`${k}=?`).join(',')} WHERE id=?`,[...keys.map(k=>v[k]),target]);return r.affectedRows?json({success:true,message:'Payment updated successfully.'}):fail('Payment not found.',404);}
  if(request.method==='DELETE'){const [r]=await sql.execute('DELETE FROM payments WHERE id=?',[id]);return r.affectedRows?json({success:true,message:'Payment deleted successfully.'}):fail('Payment not found.',404);} return fail('Method not allowed.',405);
}
async function dashboard(request) { const [u,d]=await requireUser(request,'dashboard.view');if(d)return d;const q=db();const [[a],[r],[o],[t],[l],[paid]] = await Promise.all(['SELECT COUNT(*) n FROM apartments','SELECT COUNT(*) n FROM rooms','SELECT COUNT(*) n FROM rooms WHERE status="occupied"','SELECT COUNT(*) n FROM tenants WHERE is_active=1','SELECT COUNT(*) n FROM leases WHERE status="active"','SELECT COALESCE(SUM(amount),0) n FROM payments WHERE status="paid"'].map(async s=>(await q.query(s))[0]));return json({success:true,message:'Dashboard loaded.',summary:{apartments:a.n,rooms:r.n,rooms_occupied:o.n,rooms_available:Number(r.n)-Number(o.n),tenants:t.n,active_leases:l.n,payments_paid:paid.n,occupancy_rate:Number(r.n)?Math.round(Number(o.n)/Number(r.n)*1000)/10:0}}); }
async function users(request) {
  const [actor, denied] = await requireUser(request); if (denied) return denied;
  if (actor.role !== 'Super Admin') return fail('You do not have permission to manage users.', 403);
  const url=new URL(request.url), id=Number(url.searchParams.get('id')||0), sql=db();
  if(request.method==='GET'){const [rows]=await sql.execute(`SELECT id,full_name,email,phone,role,is_active,created_at FROM users${id?' WHERE id=?':' ORDER BY full_name ASC'}`,id?[id]:[]);return json({success:true,message:'Users loaded.',[id?'user':'users']:id?rows[0]||null:rows},rows.length||!id?200:404);}
  const i=await body(request), fields=['full_name','email','phone','role','is_active'],v=clean(i,fields);
  if(request.method==='POST'){if(!i.password||String(i.password).length<6)return fail('A password of at least 6 characters is required.');const [r]=await sql.execute(`INSERT INTO users (${[...Object.keys(v),'password'].join(',')}) VALUES (${[...Object.keys(v),'password'].map(()=>'?').join(',')})`,[...Object.values(v),await bcrypt.hash(String(i.password),12)]);return json({success:true,message:'User created successfully.',user:{id:Number(r.insertId)}},201);}
  if(request.method==='PUT'){const target=Number(i.id||0),keys=Object.keys(v);if(i.password){keys.push('password');v.password=await bcrypt.hash(String(i.password),12);}const [r]=await sql.execute(`UPDATE users SET ${keys.map(k=>`${k}=?`).join(',')} WHERE id=?`,[...keys.map(k=>v[k]),target]);return r.affectedRows?json({success:true,message:'User updated successfully.'}):fail('User not found.',404);}
  if(request.method==='DELETE'){if(id===Number(actor.id))return fail('You cannot delete your own account.');const [r]=await sql.execute('DELETE FROM users WHERE id=?',[id]);return r.affectedRows?json({success:true,message:'User deleted successfully.'}):fail('User not found.',404);}return fail('Method not allowed.',405);
}
async function settings(request) {
  const [user, denied] = await requireUser(request); if (denied) return denied; const sql=db();
  if(request.method==='GET'){if(!can(user,'settings.view'))return fail('You do not have permission to view settings.',403);const [rows]=await sql.query('SELECT setting_key,setting_value FROM settings');return json({success:true,message:'Settings loaded.',settings:Object.fromEntries(rows.map(r=>[r.setting_key,r.setting_value]))});}
  if(request.method==='PUT'){if(user.role!=='Super Admin')return fail('You do not have permission to update settings.',403);const i=await body(request), values=i.settings||i;for(const [key,value] of Object.entries(values)){await sql.execute('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[key,String(value)]);}return json({success:true,message:'Settings saved successfully.'});}
  if(request.method==='POST'){const i=await body(request);if(i.action!=='change_password'||Number(i.user_id)!==Number(user.id))return fail('You can only change your own password.',403);if(!i.current_password||!i.new_password||i.new_password!==i.confirm_password||String(i.new_password).length<6)return fail('Provide a valid matching new password of at least 6 characters.');const [rows]=await sql.execute('SELECT password FROM users WHERE id=?',[user.id]);if(!rows[0]||!(await bcrypt.compare(i.current_password,rows[0].password)))return fail('Current password is incorrect.',401);await sql.execute('UPDATE users SET password=? WHERE id=?',[await bcrypt.hash(i.new_password,12),user.id]);return json({success:true,message:'Password updated successfully.'});}return fail('Method not allowed.',405);
}
async function reports(request) {
  const [user, denied]=await requireUser(request,request.method==='GET'?'reports.view':'reports.generate');if(denied)return denied;const sql=db(),url=new URL(request.url),mode=url.searchParams.get('mode')||'summary',id=Number(url.searchParams.get('id')||0);
  if(request.method==='POST'){const i=await body(request);if(!i.name||!i.entity||!i.period)return fail('Name, entity, and period are required.');const [r]=await sql.execute('INSERT INTO report_schedules (name,entity,period,created_by) VALUES (?,?,?,?)',[i.name,i.entity,i.period,user.id]);return json({success:true,message:'Periodic report schedule saved.',schedule:{id:Number(r.insertId)}},201);}
  if(request.method==='DELETE'){const [r]=await sql.execute('DELETE FROM report_schedules WHERE id=?',[id]);return r.affectedRows?json({success:true,message:'Report schedule deleted.'}):fail('Report schedule not found.',404);}
  if(mode==='schedules'){const [rows]=await sql.query('SELECT rs.*,u.full_name created_by_name FROM report_schedules rs LEFT JOIN users u ON u.id=rs.created_by WHERE rs.is_active=1 ORDER BY rs.name');return json({success:true,message:'Schedules loaded.',schedules:rows});}
  const [[a],[r],[t],[l],[paid],[pending],[open]] = await Promise.all(['SELECT COUNT(*) n FROM apartments','SELECT COUNT(*) n FROM rooms','SELECT COUNT(*) n FROM tenants WHERE is_active=1','SELECT COUNT(*) n FROM leases WHERE status="active"','SELECT COALESCE(SUM(amount),0) n FROM payments WHERE status="paid"','SELECT COALESCE(SUM(amount),0) n FROM payments WHERE status IN ("pending","overdue")','SELECT COUNT(*) n FROM maintenance_requests WHERE status IN ("open","in_progress")'].map(async q=>(await sql.query(q))[0]));
  return json({success:true,message:'Reports loaded.',summary:{apartments:a.n,rooms:r.n,tenants:t.n,active_leases:l.n,payments_paid:paid.n,outstanding_total:pending.n,open_maintenance:open.n}});
}

export default async (request) => {
  if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: { allow: 'GET, POST, PUT, DELETE, OPTIONS' } });
  try {
    const name = route(request);
    if (name === 'login') return login(request);
    if (name === 'signup') return signup(request);
    if (name === 'logout') return logout(request);
    if (name === 'me') return me(request);
    if (entities[name]) return entity(request, name);
    if (name === 'leases') return leases(request);
    if (name === 'payments') return payments(request);
    if (name === 'dashboard') return dashboard(request);
    if (name === 'users') return users(request);
    if (name === 'settings') return settings(request);
    if (name === 'reports') return reports(request);
    return fail(`The ${name} endpoint has not yet been migrated.`, 501);
  } catch (error) {
    console.error(error);
    return fail('Server error. Please try again later.', 500);
  }
};
