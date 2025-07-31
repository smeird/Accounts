# Accounts

## Development Setup

1. Create a Python virtual environment and install dependencies:
   ```bash
   python3 -m venv venv
   source venv/bin/activate
   pip install -r backend/requirements.txt
   ```
2. Configure MySQL settings in `backend/finance_manager/settings.py`.
3. Run migrations and start the development server:
   ```bash
   cd backend
   ./manage.py migrate
   ./manage.py runserver
   ```
