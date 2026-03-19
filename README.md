# 📌 Project Management System (Enterprise-Oriented)

![Status](https://img.shields.io/badge/status-active--development-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-4479A1.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)

A comprehensive, web-based Project Management System designed to support efficient project planning, tracking, and risk management in enterprise environments.

## 🏦 Recognition
This system is developed as an independent initiative for internal use within Dashen Bank SC and has received positive recognition for its practical value in improving workflow efficiency and project coordination.

## 🚀 Overview
The Project Management System provides a centralized platform for managing the full project lifecycle, including planning, execution, monitoring, and reporting. Built with a modular and scalable architecture, it supports multiple users, structured workflows, and real-time interaction across different project components.

## 🎯 Core Objectives
- Improve internal project management efficiency within enterprise environments  
- Provide a centralized and structured system for project coordination  
- Apply software engineering best practices in a real-world organizational context  

## 🛠️ Technologies Used

**Frontend:**  
- HTML5 – Semantic markup  
- CSS3 – Responsive, modern styling  
- JavaScript – Client-side interactivity  
- jQuery – Simplified DOM manipulation & AJAX  

**Backend:**  
- PHP – Server-side business logic  

**Database:**  
- MySQL – Relational database  

**Architecture:**  
- Presentation Layer: Interactive UI built with JavaScript/jQuery  
- Application Layer: Business logic implemented in PHP  
- Data Layer: MySQL database for persistent storage  

## ✨ Key Features
- **Project Planning & Tracking:** Timeline creation, milestone tracking, phase monitoring  
- **User Management Module:** Role-based access, registration, activation, deletion  
- **Project Profile Management:** Project profiles, team assignment, metadata tracking  
- **Issue Tracking System:** Log, assign, track, and comment on issues  
- **Test Case Management:** Organize tests, link to requirements, track execution  
- **Event & Task Management:** Schedule events, assign tasks, set deadlines, monitor completion  
- **Budget Management:** Track budgets, monitor expenditures, generate financial reports  
- **Risk Management Module:** Identify, assess, and mitigate risks  
- **Role-Based Access Control (RBAC):** Granular permissions, module-level access, secure authentication  
- **Dashboard & Reporting System:** Module dashboards, unified dashboard, real-time metrics, KPIs  
- **Notification System:** Alerts, email notifications, status updates  

-----------------------------------------------------------------------
## 📸 Screenshots

<div align="center">
  <i>Click on any screenshot to view full size</i>
</div>

<br>

### 🔐 Authentication
| | |
|:---:|:---:|
| **Login Page** | |
| <img src="Screenshots/login.png" alt="Login Page" width="400"/> | |

---

### 📊 Dashboard Views
| | |
|:---:|:---:|
| **Dashboard Overview 1** | **Dashboard Overview 2** |
| <img src="Screenshots/1. Dashboard1.png" alt="Dashboard 1" width="400"/> | <img src="Screenshots/Dashboard2.png" alt="Dashboard 2" width="400"/> |

---

### 👥 User Management
| | |
|:---:|:---:|
| **User Registration with Role Assignment** | **User Assignment 1** |
| <img src="Screenshots/user-registration-with-role.png" alt="User Registration" width="400"/> | <img src="Screenshots/user-assignment1.png" alt="User Assignment 1" width="400"/> |
| **User Assignment 2** | **Bulk User Assignment** |
| <img src="Screenshots/user-assignment2.png" alt="User Assignment 2" width="400"/> | <img src="Screenshots/bulk-assignment.png" alt="Bulk Assignment" width="400"/> |
| **Module Assignment** | |
| <img src="Screenshots/module-assignment.png" alt="Module Assignment" width="400"/> | |

---

### 📁 Project Management
| | |
|:---:|:---:|
| **Project Profiles** | **Project Profiles - Phases** |
| <img src="Screenshots/Project-profiles.png" alt="Project Profiles" width="400"/> | <img src="Screenshots/Project-profiles-phases.png" alt="Project Phases" width="400"/> |
| **Project Intake Form** | **Project Hierarchy** |
| <img src="Screenshots/project-intake-form.png" alt="Project Intake" width="400"/> | <img src="Screenshots/project_hierarchy.png" alt="Project Hierarchy" width="400"/> |
| **Project Scheduler** | |
| <img src="Screenshots/project-scheduler.png" alt="Project Scheduler" width="400"/> | |

---

### 💰 Budget Management
| | |
|:---:|:---:|
| **Budget Dashboard** | **Budget Report** |
| <img src="Screenshots/budget-dashboard.png" alt="Budget Dashboard" width="400"/> | <img src="Screenshots/budget-report.png" alt="Budget Report" width="400"/> |

---

### 📊 Reporting & Analytics
| | |
|:---:|:---:|
| **Consolidated Reports** | **Consolidated Reports - Projects** |
| <img src="Screenshots/consolidated_reports.png" alt="Consolidated Reports" width="400"/> | <img src="Screenshots/consolidated_reports-projects.png" alt="Consolidated Reports Projects" width="400"/> |

---

### 🐞 Issue Tracking
| | |
|:---:|:---:|
| **Issue Tracker Dashboard** | **Issue Tracker Report** |
| <img src="Screenshots/issue-tracker-dashboard.png" alt="Issue Tracker Dashboard" width="400"/> | <img src="Screenshots/issue-tracker-report.png" alt="Issue Tracker Report" width="400"/> |

---

### 📅 Event Management
| |
|:---:|
| **Event Management Dashboard** |
| <img src="Screenshots/event-management-dashboard.png" alt="Event Management Dashboard" width="400"/> |

---

### ⚠️ Risk Management
| | |
|:---:|:---:|
| **Risk Dashboard** | **Risk Report** |
| <img src="Screenshots/risk-dashboard.png" alt="Risk Dashboard" width="400"/> | <img src="Screenshots/risk-report.png" alt="Risk Report" width="400"/> |

---

### 🧪 Test Management
| |
|:---:|
| **Test Case Dashboard** |
| <img src="Screenshots/Test-case-dashboard.png" alt="Test Case Dashboard" width="400"/> |

---

<div align="center">
  <br>
  <b>🖼️ Complete Screenshot Gallery</b>
  <br><br>
  <i>The screenshots above demonstrate the key functionality and user interface of each module in the Project Management System.</i>
</div>


> **Total Screenshots: 20+ covering all major modules and functionality**

---

## 📈 Current Status
**🚧 Active Development**

**✅ Implemented Features:**  
- Core module architecture  
- User Management (complete)  
- Project Profile Management (complete)  
- Role-Based Access Control (complete)  
- Individual module dashboards  
- Basic reporting  
- Notification system foundation  

**🔄 In Progress:**  
- Performance optimization  
- UI/UX refinements  
- Advanced reporting  
- Integration enhancements  

**📅 Planned Improvements:**  
- API integration for external tools  
- Advanced analytics & visualization  
- Performance benchmarking  
- Security hardening  
- Mobile-responsive design optimization  

---

## ⚙️ Installation & Setup

**Prerequisites:**  
- Web server (Apache/Nginx recommended)  
- PHP 7.4+  
- MySQL 5.7+  
- Browser with JavaScript enabled  

**Step-by-Step Installation:**  
```bash
git clone https://github.com/MikiDevGuy/project-management-system.git
cd project-management-system
Move project to server root (XAMPP: htdocs, WAMP: www, LAMP: /var/www/html/)

Setup database in phpMyAdmin (project_management) and import SQL file

Update credentials in config/database.php

Access via http://localhost/project-management-system

Default login: admin / admin123 (change immediately)

🤝 Contributing

Fork → Create feature branch → Commit → Push → Pull Request

Report issues on GitHub with detailed description and screenshots

🔮 Future Enhancements

Short-term: API integration, analytics dashboard, email templates, export functionality
Long-term: Mobile app, real-time collaboration, AI-powered risk prediction, time tracking integration

👏 Acknowledgements

Dashen Bank SC – Organizational context and feedback

Contributors & Testers – Time and input

Open Source Community – Tools and libraries

📞 Contact & Support

GitHub Repository: https://github.com/MikiDevGuy/project-management-system

Issues: https://github.com/MikiDevGuy/project-management-system/issues

Email: mikiyaszewduscholar@gmail.com