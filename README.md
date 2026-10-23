# Project Name - SME Smart

> SME Smart is an AI-powered business automation platform that helps Malaysian SMEs streamline customer service, order processing, invoicing, appointment scheduling, and daily operations through personalized AI assistants.

---

## Team Information

| Field                  | Details                                     |
| ---------------------- | ------------------------------------------- |
| **Team Name**          | Ctrl + Future                               |
| **Team Domain**        | Domain 2                                    |
| **SDG Track (1–17)**   | SDG 8 — Decent Work and Economic Growth     |
| **Team Leader**        | Teh Sze Yu Jocelyn                          |
| **University**         | University Technology Malaysia (UTM)        |
| **Number of Members**  | 5                                           |

## Links

| Resource          | Link                                           |
| ----------------- | ---------------------------------------------- |
| GitHub Repository | https://github.com/your-username/your-project  |
| Replit Project    | https://replit.com/@your-username/your-project |
| Live Demo         | https://your-project.replit.app                |
| RunPod Endpoint   | https://api.runpod.ai/v2/your-endpoint-id      |
| Slide Deck        | https://example.com/slides                     |
| Demo Video        | https://example.com/demo-video                 |

---

## Overview

### Problem

Many Malaysian micro and small businesses rely on manual processes and disconnected applications to manage customer enquiries, orders, invoices, appointments, and daily operations. These repetitive tasks often lead to delayed responses, human errors, and inconsistent workflows. SMEs need an affordable, easy-to-learn automation solution that improves productivity while keeping employees in control.

### Solution

SME Smart is an AI-powered business automation platform that allows businesses from different industries to quickly configure personalized AI assistants. It automates customer enquiries, order processing, invoice generation, appointment scheduling, and business data management through connected workflows.

By combining AI automation with human verification, SME Smart reduces repetitive tasks, improves accuracy, and helps businesses operate more efficiently. Its reusable and customizable modules are designed for potential integration with the Orbyte ecosystem, supporting SME digital transformation and SDG 8.

### Key Features

- **Personalized AI Assistants** — Enables SMEs across different industries to quickly configure AI assistants based on their business needs.
- **AI Customer Service & Automation** — Handles customer enquiries, processes orders, generates invoices, and assists with appointment scheduling.
- **AI-Powered Business Management** — Automates product data imports, email management, and internal business information retrieval.
- **Human Verification & Control** — Allows staff to verify payments, manage approvals, and oversee important business operations.

### Tech Stack

| Layer         | Technology                   |
| ------------- | ---------------------------- |
| Frontend      | HTML, CSS, JavaScript        |
| Backend       | Python, Php                  |
| Database      | PostgreSQL                   |
| AI / Compute  | RunPod (GPU)                 |
| Hosting / Dev | Replit                       |

---

## Project Structure

```
.
├── docs/                    # Screenshots, diagrams
├── runpod/
│   └── handler.py           # RunPod serverless handler (GPU workload)
├── src/
│   ├── main.py              # Application entry point
│   └── utils.py             # Helper functions
├── submission/              # Required submission documents
│   ├── REPLIT PROOF OF USE.pdf
│   ├── RUNPOD PROOF OF USE.pdf
│   └── Submission Template SDC Hackathon.pdf
├── tests/
│   └── test_utils.py        # Unit tests
├── .env.example             # Example environment variables
├── .gitignore
├── .replit                  # Replit run configuration
├── LICENSE
├── requirements.txt         # Python dependencies
└── README.md
```

### Architecture
             
                 Customers / SME Staff
                           |
                           v
                 SME Smart Web Platform
                  (Frontend + Backend)
                           |
          +----------------+----------------+
          |                |                |
          v                v                v
      AI Services       Database       External APIs
       (Flowise)       PostgreSQL      Gmail / Calendar
          |                            Telegram / Claude
          |                            Agnes
          |
          +----> RunPod GPU
          |      (vLLM + Qwen2.5-7B-Instruct)
          |      System Prompt Generation Only
          |
          v
              Business Automation
           - Customer AI Chatbot
           - Order & Invoice Management
           - Appointment Scheduling
           - Internal AI Assistant
           - Email Processing
                           |
                           v
                 Human Verification
                     & Approval
                           |
                           v
                  Updated Business
                   Records & Status

## How to Run

### Option 1 — Run on Replit (Recommended)

1. Open the [Replit Project](https://replit.com/@your-username/your-project), or import this repository via **Create Repl → Import from GitHub**.
2. Open the **Secrets** tab (🔒) and add the variables listed in `.env.example`:
   - `RUNPOD_API_KEY`
   - `RUNPOD_ENDPOINT_ID`
3. Click **Run**. Replit uses the `.replit` file to start `python src/main.py`.

### Option 2 — Run Locally

```bash
# 1. Clone the repository
git clone https://github.com/your-username/your-project.git
cd your-project

# 2. Create and activate a virtual environment
python -m venv venv
source venv/bin/activate        # Windows: venv\Scripts\activate

# 3. Install dependencies
pip install -r requirements.txt

# 4. Configure environment variables
cp .env.example .env            # then fill in your values

# 5. Run the app
python src/main.py
```

### Run Tests

```bash
python -m unittest discover tests
```

---

## How We Use Replit *(Required)*

Describe how your team used Replit. For example:

- **Development:** Replit was used to develop, integrate, and test the SME Smart web application.

- **Hosting:** SME Smart is hosted on Replit, allowing users to access the platform through a live web application.

- **Integration:** Replit connects the frontend, backend, database, AI services, and external APIs.

- **Environment Variables:** API credentials and sensitive configuration values are managed through environment variables.

📄 Proof of use: [`submission/REPLIT PROOF OF USE.pdf`](submission/REPLIT%20PROOF%20OF%20USE.pdf)

## How We Use RunPod *(Required)*

Describe how your team used RunPod. For example:

- **Workload:** Generate customized SOP prompts based on each company's business requirements.

- **AI Model:** Qwen2.5-7B-Instruct, deployed using vLLM on RunPod GPU.

- **Integration:**The generated prompts are integrated into Flowise to configure personalized AI assistants.

- **Purpose:** Simplify AI setup and automate business workflows across different industries.

Example request:

```bash
curl -X POST https://api.runpod.ai/v2/$RUNPOD_ENDPOINT_ID/runsync \
  -H "Authorization: Bearer $RUNPOD_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"input": {"prompt": "Hello"}}'
```

📄 Proof of use: [`submission/RUNPOD PROOF OF USE.pdf`](submission/RUNPOD%20PROOF%20OF%20USE.pdf)

---

## Challenges & Learnings

- **AI Behavior Customization:** Refining customized SOP prompts to ensure consistent and accurate AI responses across different business scenarios.
- **API Authentication:** Managing OAuth authentication and permissions for Gmail and Google Calendar integrations.
- **Time Constraints:** Developing and testing multiple interconnected features within a limited hackathon timeframe.
- Throughout this hackathon, our team learned the importance of teamwork, effective communication, and task delegation based on each member's strengths. We stepped beyond our existing knowledge and comfort zones to explore new technologies, develop innovative and creative solutions, and overcome technical challenges. This experience strengthened our problem-solving skills, adaptability, and ability to work together towards a common goal.

## Future Improvements

- [ ] Expand AI integrations to more customer communication platforms.
- [ ] Introduce advanced business analytics and reporting.
- [ ] Enhance system scalability and security.
- [ ] Expand Team Workspace with employee attendance tracking, clock-in/out, leave applications, and leave balance management.

## Team

| Name                         | Role                | GitHub                                          |
| -------------------------    | ------------------- | ----------------------------------------------- | 
| Teh Sze Yu Jocelyn           | Team Lead / AI      |[@Jocelynsuppp](https://github.com/Jocelynsuppp) |
| Wong Xin Yu                  | AI/Backend          | [@Xinyuuuu0521](https://github.com/Xinyuuuu0521)|
| Goh Yi Qi                    | Backend / RunPod    | [@gohyiqi0621](https://github.com/gohyiqi0621/) |
| Muhammad Naqib Bin Md Salleh | Frontend            | [@Naqib57](https://github.com/Naqib57/)         |
| Aliff Najmi Bin Nor Zamree   | AI / Backend        | [@aliffjammy](https://github.com/aliffjammy)    |

---

## Submission Checklist

- [/ ] Team name, domain (Domain 1 / Domain 2) and SDG track filled in
- [ ] Slide deck link added
- [ ] Demo video link added
- [ ] Replit used, with proof of use in `submission/`
- [ ] RunPod used, with proof of use in `submission/`
- [ ] Submission template completed in `submission/`
- [ /] "How to Run" steps verified
- [ /] No API keys or secrets committed to the repository

## License

This project is licensed under the [Apache License 2.0](LICENSE).
