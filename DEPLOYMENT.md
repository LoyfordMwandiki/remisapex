# Deploy REMIS on Netlify

1. Create a managed MySQL database and user, then import `database/setup.sql` using the provider's SQL console.
2. Extract the deployment archive, place its contents in a Git repository, and create a Netlify site using **Import an existing project**. Connect that repository to Netlify. Do not use drag-and-drop deployment: it publishes static files only and will not build or deploy the Functions API required for login and signup.
3. In **Project configuration → Environment variables**, add every value from `.env.example` using your database provider's actual credentials. Keep `JWT_SECRET` private and use a random value of at least 32 characters.
4. Deploy. Netlify runs `npm run build`, which creates the safe static publish directory. The legacy `api/` and `config/` PHP files are never published.
5. Open the deployed site and sign in with the users imported from `database/setup.sql`.

## Required database access

The managed MySQL provider must permit network connections from Netlify Functions. Use the provider's TLS/SSL connection settings and leave `DB_SSL=true` unless its documentation explicitly says otherwise.
