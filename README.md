# Flowra

A powerful and flexible **workflow management engine for Laravel** that stores and manages workflows entirely in your
database.
It enables you to define **states**, **transitions**, and **actions** dynamically without hardcoding logic, giving you
full control over business processes.
Flowra is **inspired by Drupal's Workflow module**, bringing similar concepts of states, transitions, and approvals into
the Laravel ecosystem with a modern, database-driven approach.

---

## ✨ Features

- 🔗 **Database-Driven** – Workflows, states, and transitions are persisted in the database, allowing runtime changes
  without code deployment.
- ⚡ **Dynamic Transitions** – Define conditional rules for moving between states, including permissions and validation.
- 🔍 **Event & Observer Support** – Hook into Laravel’s event system to trigger custom actions when transitions occur.
- 🛠 **Extendable Architecture** – Easily integrate with your existing models, permissions, and business logic.
- 📊 **Workflow Tracking** – Keep a history of transitions for auditing and reporting purposes.

---

## 🚀 Installation

Require the package via Composer:

```bash
composer require mhqady/flowra
