import tkinter as tk
from tkinter import messagebox
import requests
import threading
import subprocess
import os

class ViciLauncher:
    def __init__(self, root):
        self.root = root
        self.root.title("VICIDIAL-JS HEADLESS")
        self.root.geometry("400x450")
        self.root.configure(bg="#0f172a")

        self.gateway_url = "http://localhost:3000/api"
        self.node_process = None

        # Estilos
        self.main_font = ("Arial", 10, "bold")
        self.btn_font = ("Arial", 10, "bold")

        # UI
        tk.Label(root, text="VOX SPHERE - VICIDIAL GATEWAY", bg="#0f172a", fg="#38bdf8", font=("Arial", 12, "bold")).pack(pady=20)

        # IP Entry
        tk.Label(root, text="ViciDial URL:", bg="#0f172a", fg="#94a3b8", font=self.main_font).pack()
        self.url_entry = tk.Entry(root, width=40, bg="#1e293b", fg="white", borderwidth=0, insertbackground="white")
        self.url_entry.pack(pady=5)
        self.url_entry.insert(0, "https://192.168.1.192/agc/vicidial.php?relogin=no&VD_login=10457765&VD_campaign=00004&phone_login=10457765&phone_pass=ngFoavIybh37nM8&VD_pass=PLUSER001")

        self.btn_start = tk.Button(root, text="🚀 INICIAR SESIÓN", command=self.start_gateway, 
                                    bg="#10b981", fg="white", font=self.btn_font, width=25, height=2, bd=0)
        self.btn_start.pack(pady=10)

        self.btn_logout = tk.Button(root, text="🚪 CERRAR SESIÓN", command=self.logout_gateway, 
                                     bg="#475569", fg="white", font=self.btn_font, width=25, height=2, bd=0)
        self.btn_logout.pack(pady=10)

        # Estado
        self.status_label = tk.Label(root, text="Estado: Offline", bg="#0f172a", fg="#94a3b8", font=self.main_font)
        self.status_label.pack(pady=20)

        # Log pequeño
        self.log_text = tk.Text(root, height=4, width=45, bg="#1e1e2e", fg="#10b981", font=("Courier", 8), borderwidth=0)
        self.log_text.pack(pady=5)

    def log(self, message):
        self.log_text.insert(tk.END, f"> {message}\n")
        self.log_text.see(tk.END)

    def start_gateway(self):
        url = self.url_entry.get()
        self.status_label.config(text="Estado: Iniciando...", fg="#f59e0b")
        self.log("Llamando a /api/start...")
        
        def run():
            try:
                response = requests.post(f"{self.gateway_url}/start", json={"url": url}, timeout=60)
                if response.status_code == 200:
                    self.status_label.config(text="Estado: Online", fg="#10b981")
                    self.log("Sesión activa en ViciDial.")
                    messagebox.showinfo("Éxito", "Gateway ViciDial-JS conectado correctamente.")
                else:
                    self.status_label.config(text="Estado: Error", fg="#ef4444")
                    self.log(f"Error {response.status_code}: {response.text[:50]}")
            except Exception as e:
                self.status_label.config(text="Estado: Desconectado", fg="#ef4444")
                self.log(f"Fallo de red: {str(e)[:50]}")
                messagebox.showerror("Error", f"No se pudo conectar al motor Node.js: {e}")

        threading.Thread(target=run).start()

    def logout_gateway(self):
        url = self.url_entry.get()
        # Intentar extraer el agente del URL de login
        try:
            agent_user = url.split('VD_login=')[1].split('&')[0]
        except:
            agent_user = "10457765" # Fallback

        self.status_label.config(text="Estado: Cerrando...", fg="#f59e0b")
        self.log(f"Llamando a /api/logout para agente {agent_user}...")
        
        def run():
            try:
                # Pasar el agente en el cuerpo de la petición
                response = requests.post(f"{self.gateway_url}/logout", json={"user": agent_user}, timeout=20)
                if response.status_code == 200:
                    self.status_label.config(text="Estado: Offline", fg="#94a3b8")
                    self.log("Logout confirmado por ViciDial.")
                    messagebox.showinfo("Éxito", "Sesión cerrada correctamente.")
                else:
                    self.status_label.config(text="Estado: Error", fg="#ef4444")
                    self.log(f"Error {response.status_code}")
            except Exception as e:
                self.log(f"Error: {str(e)[:50]}")

        threading.Thread(target=run).start()

    def on_closing(self):
        self.root.destroy()

if __name__ == "__main__":
    root = tk.Tk()
    app = ViciLauncher(root)
    root.protocol("WM_DELETE_WINDOW", app.on_closing)
    root.mainloop()
