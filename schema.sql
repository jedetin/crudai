CREATE TABLE company (
    id          INT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL
);

CREATE TABLE employee (
    id          INT PRIMARY KEY,
    company_id  INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) UNIQUE,
    );

CREATE TABLE employee_profile (
    id          INT PRIMARY KEY,
    employee_id INT NOT NULL UNIQUE,
    phone       VARCHAR(20),
    city        VARCHAR(100),

);