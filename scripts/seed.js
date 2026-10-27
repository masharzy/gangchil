import 'dotenv/config';import bcrypt from 'bcryptjs';import pg from 'pg';
if(!process.env.DATABASE_URL||!process.env.ADMIN_EMAIL||!process.env.ADMIN_PASSWORD)throw Error('Set DATABASE_URL ADMIN_EMAIL ADMIN_PASSWORD');
if(process.env.ADMIN_PASSWORD.length<12)throw Error('ADMIN_PASSWORD must have at least 12 characters');
const db=new pg.Pool({connectionString:process.env.DATABASE_URL,ssl:process.env.DATABASE_URL.includes('localhost')?false:{rejectUnauthorized:true}});
await db.query('INSERT INTO users(name,email,password_hash,role) VALUES($1,$2,$3,$4) ON CONFLICT(email) DO NOTHING',['Owner',process.env.ADMIN_EMAIL.toLowerCase(),await bcrypt.hash(process.env.ADMIN_PASSWORD,12),'owner']);
for(const name of ['Kabsa','Rice & Biryani','Chicken','Drinks'])await db.query('INSERT INTO categories(name) SELECT $1 WHERE NOT EXISTS(SELECT 1 FROM categories WHERE name=$1)',[name]);
for(let i=1;i<=12;i++)await db.query('INSERT INTO dining_tables(name) SELECT $1 WHERE NOT EXISTS(SELECT 1 FROM dining_tables WHERE name=$1)',['Table '+i]);
console.log('Owner, categories, tables ready. Add actual menu via owner dashboard.');await db.end();