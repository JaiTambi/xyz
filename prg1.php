app/

main.py

db.py

models.py

routers/

csv_tools.py

contacts.py

expenses.py

scraper.py

templates/

base.html

index.html

csv_tools.html

contacts.html

expenses.html

scraper.html

static/

styles.css

requirements.txt

README.md

requirements.txt

fastapi

uvicorn[standard]

jinja2

python-multipart

sqlmodel

pandas

beautifulsoup4

requests

pydantic

matplotlib (optional, if you want charts as images)

aiofiles (optional, if saving uploads)

app/db.py

Sets up SQLite and session.

from sqlmodel import SQLModel, create_engine, Session

engine = create_engine("sqlite:///app.db", echo=False)

def init_db():
SQLModel.metadata.create_all(engine)

def get_session():
with Session(engine) as session:
yield session

app/models.py
from typing import Optional
from sqlmodel import SQLModel, Field
from datetime import datetime

class Contact(SQLModel, table=True):
id: Optional[int] = Field(default=None, primary_key=True)
name: str
email: Optional[str] = None
phone: Optional[str] = None
notes: Optional[str] = None

class Expense(SQLModel, table=True):
id: Optional[int] = Field(default=None, primary_key=True)
date: datetime
category: str
description: Optional[str] = None
amount: float

app/routers/csv_tools.py

Upload CSV, show preview, clean (drop duplicates, trim spaces, choose columns), download cleaned CSV.

from fastapi import APIRouter, Request, UploadFile, File, Form
from fastapi.responses import HTMLResponse, StreamingResponse
from fastapi.templating import Jinja2Templates
import pandas as pd
from io import StringIO, BytesIO

router = APIRouter(prefix="/csv", tags=["CSV"])
templates = Jinja2Templates(directory="app/templates")

@router.get("", response_class=HTMLResponse)
def csv_home(request: Request):
return templates.TemplateResponse("csv_tools.html", {"request": request})

@router.post("/preview", response_class=HTMLResponse)
async def csv_preview(request: Request, file: UploadFile = File(...)):
content = (await file.read()).decode("utf-8", errors="ignore")
df = pd.read_csv(StringIO(content))
head_html = df.head(20).to_html(classes="table", index=False)
cols = list(df.columns)
request.session = {"csv_raw": content, "csv_cols": cols} # simple in-memory; replace with cache if needed
return templates.TemplateResponse("csv_tools.html", {"request": request, "preview": head_html, "columns": cols})

@router.post("/clean")
async def csv_clean(request: Request,
drop_duplicates: bool = Form(False),
trim_spaces: bool = Form(False),
keep_columns: str = Form("")):
# In a real app, store content server-side; here we expect it to be posted again or cached.
# For simplicity, this endpoint expects the raw CSV in a hidden textarea named 'raw'.
form = await request.form()
raw = form.get("raw")
if raw is None:
return HTMLResponse("No CSV provided", status_code=400)
df = pd.read_csv(StringIO(raw))
if trim_spaces:
df = df.applymap(lambda x: x.strip() if isinstance(x, str) else x)
if keep_columns:
cols = [c.strip() for c in keep_columns.split(",") if c.strip() in df.columns]
if cols:
df = df[cols]
if drop_duplicates:
df = df.drop_duplicates()
out = BytesIO()
df.to_csv(out, index=False)
out.seek(0)
return StreamingResponse(out, media_type="text/csv", headers={"Content-Disposition": "attachment; filename=cleaned.csv"})

app/routers/contacts.py

Basic CRUD for contacts.

from fastapi import APIRouter, Request, Form, Depends
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates
from sqlmodel import select
from app.db import get_session
from app.models import Contact

router = APIRouter(prefix="/contacts", tags=["Contacts"])
templates = Jinja2Templates(directory="app/templates")

@router.get("", response_class=HTMLResponse)
def list_contacts(request: Request, session=Depends(get_session), q: str = ""):
stmt = select(Contact)
if q:
stmt = stmt.where((Contact.name.contains(q)) | (Contact.email.contains(q)) | (Contact.phone.contains(q)))
contacts = session.exec(stmt).all()
return templates.TemplateResponse("contacts.html", {"request": request, "contacts": contacts, "q": q})

@router.post("/add")
def add_contact(name: str = Form(...), email: str = Form(None), phone: str = Form(None), notes: str = Form(None), session=Depends(get_session)):
contact = Contact(name=name, email=email, phone=phone, notes=notes)
session.add(contact)
session.commit()
return RedirectResponse(url="/contacts", status_code=303)

@router.post("/delete/{contact_id}")
def delete_contact(contact_id: int, session=Depends(get_session)):
c = session.get(Contact, contact_id)
if c:
session.delete(c)
session.commit()
return RedirectResponse(url="/contacts", status_code=303)

@router.post("/update/{contact_id}")
def update_contact(contact_id: int,
name: str = Form(...),
email: str = Form(None),
phone: str = Form(None),
notes: str = Form(None),
session=Depends(get_session)):
c = session.get(Contact, contact_id)
if c:
c.name, c.email, c.phone, c.notes = name, email, phone, notes
session.add(c)
session.commit()
return RedirectResponse(url="/contacts", status_code=303)

app/routers/expenses.py

Upload CSV of transactions; parse date, amount; show summaries (by month/category), totals, and simple charts.

from fastapi import APIRouter, Request, UploadFile, File, Form, Depends
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates
from sqlmodel import select
from datetime import datetime
import pandas as pd
from io import StringIO
from app.models import Expense
from app.db import get_session

router = APIRouter(prefix="/expenses", tags=["Expenses"])
templates = Jinja2Templates(directory="app/templates")

@router.get("", response_class=HTMLResponse)
def expenses_home(request: Request, session=Depends(get_session)):
expenses = session.exec(select(Expense)).all()
total = sum(e.amount for e in expenses)
by_cat = {}
for e in expenses:
by_cat[e.category] = by_cat.get(e.category, 0) + e.amount
return templates.TemplateResponse("expenses.html", {"request": request, "expenses": expenses, "total": total, "by_cat": by_cat})

@router.post("/add")
def add_expense(date: str = Form(...), category: str = Form(...), amount: float = Form(...), description: str = Form(""), session=Depends(get_session)):
e = Expense(date=datetime.fromisoformat(date), category=category, amount=amount, description=description)
session.add(e); session.commit()
return RedirectResponse(url="/expenses", status_code=303)

@router.post("/upload")
async def upload_expenses(file: UploadFile = File(...), session=Depends(get_session)):
content = (await file.read()).decode("utf-8", errors="ignore")
df = pd.read_csv(StringIO(content))
# Try to infer columns
col_map = {c.lower(): c for c in df.columns}
date_col = col_map.get("date") or col_map.get("transaction_date") or list(df.columns)
amount_col = col_map.get("amount") or col_map.get("debit") or col_map.get("credit")
cat_col = col_map.get("category") or "Uncategorized"
for _, row in df.iterrows():
date = pd.to_datetime(row[date_col], errors="coerce")
amt = float(row[amount_col]) if amount_col in df.columns else 0.0
cat = row[cat_col] if cat_col in df.columns else "Uncategorized"
desc = " | ".join(str(row[c]) for c in df.columns if c not in [date_col, amount_col])
if pd.isna(date):
continue
session.add(Expense(date=date.to_pydatetime(), category=str(cat), description=desc, amount=amt))
session.commit()
return RedirectResponse(url="/expenses", status_code=303)

app/routers/scraper.py

Input URL and CSS selector; fetch HTML and display extracted text.

from fastapi import APIRouter, Request, Form
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates
import requests
from bs4 import BeautifulSoup

router = APIRouter(prefix="/scraper", tags=["Scraper"])
templates = Jinja2Templates(directory="app/templates")

@router.get("", response_class=HTMLResponse)
def scraper_home(request: Request):
return templates.TemplateResponse("scraper.html", {"request": request})

@router.post("/run", response_class=HTMLResponse)
def scraper_run(request: Request, url: str = Form(...), selector: str = Form("p")):
try:
resp = requests.get(url, timeout=10, headers={"User-Agent": "Mozilla/5.0"})
resp.raise_for_status()
except Exception as e:
return templates.TemplateResponse("scraper.html", {"request": request, "error": str(e)})
soup = BeautifulSoup(resp.text, "html.parser")
results = [el.get_text(strip=True) for el in soup.select(selector)]
return templates.TemplateResponse("scraper.html", {"request": request, "url": url, "selector": selector, "results": results[:50]})

app/main.py
from fastapi import FastAPI, Request
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from app.db import init_db
from app.routers import csv_tools, contacts, expenses, scraper

app = FastAPI(title="Data Tools Hub")
templates = Jinja2Templates(directory="app/templates")
app.mount("/static", StaticFiles(directory="app/static"), name="static")

app.include_router(csv_tools.router)
app.include_router(contacts.router)
app.include_router(expenses.router)
app.include_router(scraper.router)

@app.on_event("startup")
def on_startup():
init_db()

@app.get("/")
def index(request: Request):
return templates.TemplateResponse("index.html", {"request": request})

Templates (minimal)
app/templates/base.html

<!doctype html> <html> <head> <meta charset="utf-8"> <title>Data Tools Hub</title> <link rel="stylesheet" href="/static/styles.css"> </head> <body> <header> <h1>Data Tools Hub</h1> <nav> <a href="/">Home</a> <a href="/csv">CSV Cleaner</a> <a href="/contacts">Contacts</a> <a href="/expenses">Expenses</a> <a href="/scraper">Scraper</a> </nav> </header> <main> {% block content %}{% endblock %} </main> </body> </html>
app/templates/index.html
{% extends "base.html" %}
{% block content %}

<p>Welcome! Choose a tool from the navigation.</p> {% endblock %}
app/templates/csv_tools.html
{% extends "base.html" %}
{% block content %}

<h2>CSV Cleaner</h2> <form action="/csv/preview" method="post" enctype="multipart/form-data"> <input type="file" name="file" accept=".csv" required> <button type="submit">Preview</button> </form> {% if preview %} <h3>Preview</h3> <div>{{ preview|safe }}</div> <form action="/csv/clean" method="post"> <input type="hidden" name="raw" value="{{ request.session.csv_raw if request.session else '' }}"> <label><input type="checkbox" name="drop_duplicates"> Drop duplicates</label> <label><input type="checkbox" name="trim_spaces"> Trim spaces</label> <label>Keep columns (comma-separated): <input name="keep_columns" placeholder="col1, col2"></label> <button type="submit">Download Cleaned CSV</button> </form> {% endif %} {% endblock %}
app/templates/contacts.html
{% extends "base.html" %}
{% block content %}

<h2>Contacts</h2> <form action="/contacts" method="get"> <input name="q" value="{{ q or '' }}" placeholder="Search"> <button type="submit">Search</button> </form> <form action="/contacts/add" method="post"> <input name="name" placeholder="Name" required> <input name="email" placeholder="Email"> <input name="phone" placeholder="Phone"> <input name="notes" placeholder="Notes"> <button type="submit">Add</button> </form> <table> <tr><th>Name</th><th>Email</th><th>Phone</th><th>Notes</th><th>Actions</th></tr> {% for c in contacts %} <tr> <form action="/contacts/update/{{ c.id }}" method="post"> <td><input name="name" value="{{ c.name }}"></td> <td><input name="email" value="{{ c.email or '' }}"></td> <td><input name="phone" value="{{ c.phone or '' }}"></td> <td><input name="notes" value="{{ c.notes or '' }}"></td> <td> <button type="submit">Save</button> </form> <form action="/contacts/delete/{{ c.id }}" method="post" style="display:inline"> <button type="submit">Delete</button> </form> </td> </tr> {% endfor %} </table> {% endblock %}
app/templates/expenses.html
{% extends "base.html" %}
{% block content %}

<h2>Expenses</h2> <form action="/expenses/add" method="post"> <input type="date" name="date" required> <input name="category" placeholder="Category" required> <input type="number" step="0.01" name="amount" placeholder="Amount" required> <input name="description" placeholder="Description"> <button type="submit">Add</button> </form> <form action="/expenses/upload" method="post" enctype="multipart/form-data"> <input type="file" name="file" accept=".csv"> <button type="submit">Upload CSV</button> </form> <p>Total: {{ "%.2f"|format(total or 0) }}</p> <h3>By Category</h3> <ul> {% for k, v in by_cat.items() %} <li>{{ k }}: {{ "%.2f"|format(v) }}</li> {% endfor %} </ul> <h3>All Expenses</h3> <table> <tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr> {% for e in expenses %} <tr> <td>{{ e.date.date() }}</td> <td>{{ e.category }}</td> <td>{{ e.description }}</td> <td>{{ "%.2f"|format(e.amount) }}</td> </tr> {% endfor %} </table> {% endblock %}
app/templates/scraper.html
{% extends "base.html" %}
{% block content %}

<h2>Web Scraper</h2> <form action="/scraper/run" method="post"> <input name="url" placeholder="https://example.com" required> <input name="selector" value="{{ selector or 'p' }}" placeholder="CSS selector (e.g., h2, .class, #id)"> <button type="submit">Scrape</button> </form> {% if error %}<p style="color:red">{{ error }}</p>{% endif %} {% if results %} <h3>Results for {{ url }}</h3> <ol> {% for r in results %} <li>{{ r }}</li> {% endfor %} </ol> {% endif %} {% endblock %}
app/static/styles.css
body { font-family: system-ui, sans-serif; margin: 24px; }
header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
nav a { margin-right: 12px; }
table { border-collapse: collapse; width: 100%; }
td, th { border: 1px solid #ddd; padding: 6px; }
input, button { margin: 4px; }